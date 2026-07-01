@extends('documents.layout')

@section('doc-type', 'Receipt')

@php $currency = $company['currency'] ?? ''; @endphp

@section('body')
    <table style="width:100%">
        <tr>
            <td style="width:50%; vertical-align:top">
                <h2>Received from</h2>
                <table class="kv">
                    <tr><td class="label">Client</td><td>{{ $client['name'] ?? '—' }}</td></tr>
                    @if(!empty($client['phone']))
                        <tr><td class="label">Phone</td><td>{{ $client['phone'] }}</td></tr>
                    @endif
                    <tr><td class="label">Deal</td><td>#{{ $project['id'] ?? '—' }}@if(!empty($project['unit_reference'])) · {{ $project['unit_reference'] }}@endif</td></tr>
                </table>
            </td>
            <td style="width:50%; vertical-align:top">
                <h2>Payment</h2>
                <table class="kv">
                    <tr><td class="label">Date</td><td>{{ $versement['paid_on'] ?? '—' }}</td></tr>
                    <tr><td class="label">Method</td><td>{{ $versement['method'] ?? '—' }}</td></tr>
                    @if(!empty($versement['reference']))
                        <tr><td class="label">Reference</td><td>{{ $versement['reference'] }}</td></tr>
                    @endif
                    @if(!empty($versement['installment_no']))
                        <tr><td class="label">Instalment</td><td>#{{ $versement['installment_no'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <h2>Amount received</h2>
    <table class="lines">
        <thead>
            <tr><th>Description</th><th class="text-right">Amount ({{ $currency }})</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Instalment payment for deal #{{ $project['id'] ?? '' }}</td>
                <td class="text-right amount">{{ $versement['amount'] ?? '0.00' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="total-box">
        @if(isset($balance['total_price']) && $balance['total_price'] !== null)
            <table style="width:100%">
                <tr class="row"><td class="label">Deal total</td><td class="text-right">{{ $balance['total_price'] }} {{ $currency }}</td></tr>
                <tr class="row"><td class="label">Total paid to date</td><td class="text-right">{{ $balance['total_paid'] }} {{ $currency }}</td></tr>
                <tr class="row grand"><td>Outstanding balance</td><td class="text-right">{{ $balance['outstanding'] }} {{ $currency }}</td></tr>
            </table>
        @else
            <table style="width:100%">
                <tr class="row grand"><td>Amount received</td><td class="text-right">{{ $versement['amount'] ?? '0.00' }} {{ $currency }}</td></tr>
            </table>
        @endif
    </div>
@endsection
