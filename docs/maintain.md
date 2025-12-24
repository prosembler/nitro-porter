# Maintainer Guide

Mastered the **[User Guide](/guide)** and **[Developer Guide](/develop)** 
and ready to help with core Nitro Porter work? Buckle up.

## Setup

You'll want to clone the repo and run `composer install` on it. 
This will include the development dependencies you'll need.

You can optionally copy `phpstan.neon.dist` and `phpunit.xml.dist`, removing the `.dist` from both, 
to customize them as needed (without committing them — they are ignored by git).

## Quality Tools

* `composer lint` runs linting (PHPCS) with the PSR12 standard.
* `composer delint` runs a fix for linting issues (PHPCBF). It does its best.
* `composer stan` runs static analysis (PHPStan) as defined in `phpstan.neon.dist`.

PHPStan is currently at level 6 with exceptions for the deprecated `src/Database`,
the `src/Source/*` packages that need updating (still at level 5),
and rule `missingType.iterableValue` which wants `mixed[]` all over the docblocks (which should get solved with nicer
array use / reducing usage of them, which should likely wait for the previous 2 issues to get updated).
Those same issues are the primary hurdles to level 7, it seems.

## Testing Tools

Use `composer test` to run the _unit_ test suite (_only_ — see `phpunit.xml.dist`). This runs in the CI pipeline.

Use `composer testall` to _also_ run _integration_ tests. It runs mock data tests against databases and takes more time to run. It does **NOT** run in the CI pipeline.

This project desperately needs more **integration tests** that simulate an actual migration. 
Faker & Phinx are available for this purpose (see `composer phinx` and `composer seed`).

## Commits & PRs

You must use `fix: ` and `feat:` prefixes on your commit messages for the [changelog automation](https://www.conventionalcommits.org/en/v1.0.0/#summary) to work.

Please use thoughtful, concise commit messages and make use of rebase & squash when preparing a pull request if you're able.

Our _unit of work_ is the commit, not the PR. 
It is therefore preferable to have multiple atomic commits than to squash an entire complex PR into 1 commit.
If you don't know what this means, that's OK! You can contribute anyway and we'll figure it out as we go.

## Releases

Use `composer changelog` to run `conventional-changelog`, which will update the `CHANGELOG.md`. 
We use [semantic versioning](https://semver.org/). 
If there are `feat:` commits since the last tag, it will assume a minor-point release.
Otherwise, it will assume a patch-level release.

Create a git tag in the form `v0.0.0` to start a new release and finish the process on GitHub.

Ready-built ZIP files are distributed on GitHub with each release. 
Build them with `composer build`, which triggers a Phing build in `/build/current`.
You will need to manually compress the generated folder into a ZIP.

## Build Docs (localhost)
This is an advanced setup NOT required to contribute to the docs. It simply lets you run the documentation website on your localhost.

Install MkDocs Material and necessary plugins. On MacOS, use `pip3` instead of `pip`:

    pip install -r requirements.txt

From the root of this repo, start the built-in webserver to preview your work on the documentation:

    mkdocs serve

You should now be able to view the docs at: `http://127.0.0.1:8000/` (paste it in your browser).

To stop serving, use `Ctrl + C` or close the Terminal window. To restart, just use `mkdocs serve` again.

### Troubleshooting

#### command not found: mkdocs
If you get this on MacOS, it's likely not in your `PATH`. Take the output of `python3 -m site --user-base` and add `/bin` to it (ex: `/Users/linc/Library/Python/3.9/bin`), add that as a new line in `/etc/paths` (requires `sudo`), and **restart Terminal**.

#### Error: Config file 'mkdocs.yml' does not exist.
You are not in the root of this repo. Use the `cd` command to move there.
