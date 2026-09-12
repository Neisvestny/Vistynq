/**
 * ESLint flat config for the frontend workspace.
 *
 * The `no-silent-suppressions` rule enforces the team convention that
 * `// eslint-disable*` and `// @ts-ignore` may only be used together with a
 * tracked marker comment explaining the suppression. There is no built-in rule
 * for that, so it is implemented as a small rule defined here.
 */
import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'
import globals from 'globals'

const requireTodoForDirective = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Require a tracked TODO marker next to eslint-disable* or @ts-ignore directives',
    },
    messages: {
      missingTodo:
        '{{directive}} requires a tracked TODO marker — explain why the suppression is needed.',
    },
  },
  create(context) {
    const sourceCode = context.sourceCode
    return {
      Program() {
        for (const comment of sourceCode.ast.comments) {
          const text = comment.value
          for (const directive of ['eslint-disable', '@ts-ignore']) {
            if (text.includes(directive) && !text.toUpperCase().includes('TODO')) {
              context.report({
                node: comment,
                messageId: 'missingTodo',
                data: { directive: `//${directive}` },
              })
            }
          }
        }
      },
    }
  },
}

export default tseslint.config(
  {
    name: 'app/files-to-lint',
    files: ['**/*.{ts,vue}'],
  },
  {
    name: 'app/files-to-ignore',
    ignores: ['**/dist/**', '**/dist-ssr/**', '**/coverage/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  {
    name: 'app/suppression-conventions',
    files: ['**/*.{ts,vue}'],
    plugins: { local: { rules: { 'require-todo-for-directive': requireTodoForDirective } } },
    rules: {
      'local/require-todo-for-directive': 'error',
    },
  },
  {
    name: 'app/typescript',
    files: ['**/*.{ts,vue}'],
    languageOptions: {
      globals: globals.browser,
      parserOptions: {
        parser: tseslint.parser,
      },
    },
  },
  {
    name: 'app/prettier-compat',
    files: ['**/*.vue'],
    rules: {
      'vue/html-self-closing': 'off',
      'vue/html-indent': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/html-closing-bracket-spacing': 'off',
      'vue/html-quotes': 'off',
      'vue/html-quote-spacing': 'off',
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/first-attribute-linebreak': 'off',
      'vue/no-multi-spaces': 'off',
      'vue/multiline-ternary': 'off',
    },
  },
  {
    name: 'app/todos-are-tracked',
    rules: {
      'no-warning-comments': ['warn', { terms: ['TODO'], location: 'anywhere' }],
    },
  }
)
