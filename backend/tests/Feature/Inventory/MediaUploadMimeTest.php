<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Media\UploadMimeDetector;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Upload mime resolution for real-world Office files. libmagic's verdict for a
 * genuine PowerPoint/Word/Excel file varies with how the producer laid out the
 * container — slideshow/template/macro variants have their own mimes, and some
 * files only sniff as bare "application/zip" or an OLE blob. UploadMimeDetector
 * looks inside opaque containers; the gate stays content-based (an .exe or an
 * arbitrary zip never passes, whatever its name).
 */
class MediaUploadMimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function editor(): User
    {
        return $this->userWithPermissions(['units.view', 'media.manage']);
    }

    /** @return array<string, array{string, string, string}> mime, filename, expected type */
    public static function officeVariants(): array
    {
        return [
            'ppsx slideshow' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'visite.ppsx', 'pptx'],
            'pptm macro deck' => ['application/vnd.ms-powerpoint.presentation.macroEnabled.12', 'deck.pptm', 'pptx'],
            'potx template' => ['application/vnd.openxmlformats-officedocument.presentationml.template', 'modele.potx', 'pptx'],
            'docm macro doc' => ['application/vnd.ms-word.document.macroEnabled.12', 'contrat.docm', 'docx'],
            'xlsm macro sheet' => ['application/vnd.ms-excel.sheet.macroEnabled.12', 'prix.xlsm', 'xlsx'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('officeVariants')]
    public function test_office_variant_mimes_are_accepted(string $mime, string $name, string $expectedType): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create($name, 200, $mime),
            'collection' => 'presentations',
        ])->assertCreated()
            ->assertJsonPath('data.type', $expectedType)
            ->assertJsonPath('data.preview_status', 'pending');
    }

    public function test_a_pptx_that_only_sniffs_as_zip_is_accepted_and_canonicalized(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        // Real bytes: a zip whose [Content_Types].xml declares presentationml —
        // uploaded as a genuine (non-faked) file so the whole chain runs on
        // whatever libmagic actually says about it.
        $path = $this->makeOoxmlishZip('presentationml.presentation');

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => new UploadedFile($path, 'Résidence Yasmine.pptx', 'application/octet-stream', null, true),
            'collection' => 'presentations',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'pptx')
            ->assertJsonPath(
                'data.mime_type',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            );
    }

    public function test_an_arbitrary_zip_is_still_rejected(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'just a zip');
        $zip->close();

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => new UploadedFile($path, 'archive.pptx', 'application/zip', null, true),
        ])->assertStatus(422)->assertJsonValidationErrorFor('file');
        @unlink($path);
    }

    public function test_executables_are_still_rejected(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create('setup.pptx', 8, 'application/x-msdownload'),
        ])->assertStatus(422)->assertJsonValidationErrorFor('file');
    }

    public function test_detector_resolves_opaque_containers(): void
    {
        $detector = new UploadMimeDetector;

        // OOXML families inside a bare-zip verdict (Testing\File pins the
        // declared mime, forcing the opaque branch deterministically).
        foreach ([
            'presentationml.presentation' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'wordprocessingml.document' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'spreadsheetml.sheet' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ] as $family => $expected) {
            $file = $this->fakeWithRealBytes($this->makeOoxmlishZip($family), 'f.bin', 'application/zip');
            $this->assertSame($expected, $detector->detect($file), $family);
        }

        // ODF: the `mimetype` zip entry wins.
        $odp = tempnam(sys_get_temp_dir(), 'odp');
        $zip = new \ZipArchive;
        $zip->open($odp, \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
        $zip->addFromString('content.xml', '<office/>');
        $zip->close();
        $this->assertSame(
            'application/vnd.oasis.opendocument.presentation',
            $detector->detect($this->fakeWithRealBytes($odp, 'expo.odp', 'application/zip')),
        );

        // Legacy OLE blob: the container is verified, the extension picks the family.
        $ole = TestingFile::create('old-deck.ppt', 10);
        $ole->mimeTypeToReport = 'application/CDFV2';
        $this->assertSame('application/vnd.ms-powerpoint', $detector->detect($ole));

        // …but an OLE blob with a non-office extension stays opaque (rejected upstream).
        $blob = TestingFile::create('data.bin', 10);
        $blob->mimeTypeToReport = 'application/CDFV2';
        $this->assertSame('application/CDFV2', $detector->detect($blob));

        // A plain zip with neither marker stays a zip.
        $plain = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($plain, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'nothing office here');
        $zip->close();
        $this->assertSame('application/zip', $detector->detect($this->fakeWithRealBytes($plain, 'x.zip', 'application/zip')));
    }

    /** A zip carrying an OOXML-style [Content_Types].xml for the given family. */
    private function makeOoxmlishZip(string $family): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        // Deliberately NOT first in the archive — the layout that trips libmagic.
        $zip->addFromString('docProps/app.xml', '<Properties/>');
        $zip->addFromString(
            '[Content_Types].xml',
            '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.'.$family.'.main+xml"/></Types>',
        );
        $zip->close();

        return $path;
    }

    /** A Testing\File whose declared mime is pinned but whose bytes are real. */
    private function fakeWithRealBytes(string $sourcePath, string $name, string $mime): TestingFile
    {
        $file = new TestingFile($name, fopen($sourcePath, 'rb'));
        $file->mimeTypeToReport = $mime;

        return $file;
    }
}
