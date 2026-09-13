<!--
Thanks for sending a pull request! Please fill in the sections below to help us review faster.

For end-user questions, do NOT open a PR — use the wp.org support forum:
https://wordpress.org/support/plugin/diluxone-offload/

For security vulnerabilities, do NOT open a public PR — use GitHub Security Advisories:
https://github.com/soydiloreto/diluxone-offload-wordpress/security/advisories/new
-->

## Summary

<!-- One or two sentences: what does this PR change, at a high level? -->

## Why

<!--
What problem does this solve? Link any related issue (e.g. "Closes #42").
Explain motivation and context — what was wrong, what's better now, what alternatives you considered.
-->

## Type of change

<!-- Check all that apply. -->

- [ ] 🐛 Bug fix (non-breaking change that fixes a defect)
- [ ] ✨ New feature (non-breaking change that adds user-visible functionality)
- [ ] 💥 Breaking change (a fix or feature that would change existing behavior in an incompatible way)
- [ ] 🧹 Refactor / internal change (no user-visible behavior change)
- [ ] 📝 Documentation only
- [ ] 🔧 Tooling / CI / build system
- [ ] 🌐 Translations

## Test plan

<!--
How did you verify this works? Be specific.
- For bug fixes: how did you reproduce the bug, how did you confirm it's fixed?
- For features: what scenarios did you exercise?
- For refactors: how did you confirm no behavior changed?
-->

- [ ] Manually tested on a local WordPress install
- [ ] CI checks pass (PHP lint, Plugin Check, readme validation)
- [ ] Tested with Azure Blob Storage provider
- [ ] Tested on multisite (if relevant)

## Checklist

- [ ] Branch follows the naming convention from `CONTRIBUTING.md` (`feat/`, `fix/`, `chore/`, `docs/`, `ci/`, `refactor/`, `style/`).
- [ ] Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/).
- [ ] User-facing strings are wrapped in WordPress translation functions with the `diluxone-offload` text domain.
- [ ] User input is sanitized; output is escaped.
- [ ] No credentials, API keys, or secrets are logged.
- [ ] If this changes user-visible behavior, the `readme.txt` `== Changelog ==` section is updated.

## Screenshots / recordings

<!-- Optional but very welcome for UI changes. -->
