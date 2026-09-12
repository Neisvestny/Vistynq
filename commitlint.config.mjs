export default {
  extends: ['@commitlint/config-conventional'],
  helpUrl: 'https://github.com/conventional-commits/conventionalcommits.org#readme',
  defaultIgnores: true,
  plugins: [
    {
      rules: {
        'subject-starting-lowercase': (parsed) => {
          const { subject } = parsed
          if (!subject) return [true]
          const startsLower = /^[a-z0-9]/.test(subject)
          return [
            startsLower,
            'subject must start with a lowercase letter or a digit (e.g. "fix: fix the login bug")',
          ]
        },
        'header-ascii-only': (parsed) => {
          const { header } = parsed
          if (!header) return [true]
          const asciiOnly = /^[\x20-\x7E]+$/.test(header)
          return [asciiOnly, 'commit message must be written in English (ASCII only)']
        },
      },
    },
  ],
  rules: {
    'type-enum': [
      2,
      'always',
      ['feat', 'fix', 'chore', 'refactor', 'docs', 'style', 'test', 'perf', 'build', 'ci', 'revert'],
    ],
    'type-case': [2, 'always', 'lower-case'],
    'scope-case': [2, 'always', 'kebab-case'],
    'header-max-length': [2, 'always', 72],
    'subject-full-stop': [2, 'never', '.'],
    'body-leading-blank': [1, 'always'],
    'subject-case': [2, 'never', ['start-case', 'pascal-case', 'upper-case']],
    'subject-starting-lowercase': [2, 'always'],
    'header-ascii-only': [2, 'always'],
  },
  async: true,
}
