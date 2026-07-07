/* eslint-env node */
module.exports = {
    root: true,
    env: {
        browser: true,
        es2022: true,
        node: true,
    },
    extends: [
        'eslint:recommended',
        'plugin:vue/vue3-recommended',
        'prettier', // must be last: turns off stylistic rules Prettier owns
    ],
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
    },
    rules: {
        'vue/multi-word-component-names': 'off',
    },
    // android/ = Capacitor shell (generated bridge js inside); native-shell/ = its stub webDir
    ignorePatterns: ['dist/', 'node_modules/', 'coverage/', 'android/', 'native-shell/'],
}
