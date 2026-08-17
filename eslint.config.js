import js from '@eslint/js';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
import typescript from 'typescript-eslint';

export default [
    js.configs.recommended,
    ...typescript.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['resources/js/**/*.{ts,vue}'],
        languageOptions: {
            globals: { ...globals.browser },
            parserOptions: { parser: typescript.parser, extraFileExtensions: ['.vue'] },
        },
        plugins: { import: importPlugin },
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports' }],
            'import/order': ['error', { alphabetize: { order: 'asc', caseInsensitive: true } }],
            'vue/multi-word-component-names': 'off',
            'vue/require-default-prop': 'off',
        },
    },
    {
        ignores: [
            'vendor', 'node_modules', 'public', 'bootstrap/ssr',
            'resources/js/actions/**', 'resources/js/routes/**', 'resources/js/wayfinder/**',
        ],
    },
    prettier,
];