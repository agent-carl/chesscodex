import js from '@eslint/js';
import globals from 'globals';

// Modules are the entry points bundled by tools/build.mjs; the rest are classic scripts.
const modules = ['app', 'play', 'search', 'train', 'repertoire'].map((n) => `public/${n}.js`);

export default [
  { ignores: ['**/*.min.js', 'vendor/**', '_composer_vendor/**', 'node_modules/**', '.dev/**', '.claude/**', 'docs/**', 'backups/**', 'tools/**'] },
  js.configs.recommended,
  {
    files: ['public/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'script',
      globals: { ...globals.browser },
    },
    rules: {
      'no-empty': ['error', { allowEmptyCatch: true }],
      'no-var': 'error',
      'prefer-const': 'error',
    },
  },
  { files: ['tests/js/**/*.mjs'], languageOptions: { ecmaVersion: 'latest', sourceType: 'module', globals: { ...globals.node } } },
  { files: modules, languageOptions: { sourceType: 'module' } },
  { files: ['public/sw.js'], languageOptions: { globals: { ...globals.serviceworker } } },
];
