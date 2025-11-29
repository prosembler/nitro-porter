# User Guide

## Installation

'!!! warning "Localhost Development Only"
Don't use this in Production or anywhere publicly accessible

### Docker Desktop (recommended)

- Install Docker Desktop on [Mac](https://docs.docker.com/desktop/setup/install/mac-install/), [Windows](https://docs.docker.com/desktop/setup/install/windows-install/), or [Linux](https://docs.docker.com/desktop/setup/install/linux/).
- Download the [latest Nitro Porter](https://github.com/prosembler/nitro-porter/releases) & unzip it.
- Open a terminal window and from the new folder run: `./bin/setup.sh`

The containers may take a moment to build.
If all goes well, it should **immediately connect you to the shell** in the Docker container (in `/app`).
The copy of Nitro Porter you downloaded is mounted inside the container. It's the same files.

You must be connected to the Docker container shell to continue below!
It is safe to re-run `./bin/setup.sh` any time.

You can access phpMyAdmin by visiting `localhost:8082` in your browser. Use this to import your database(s).

### Manual Localhost (alternate)

!!! info "Non-Docker Only"
You don't need to complete this section if you're using Docker Desktop. Skip to "Basic Usage" below.

If you're doing many migrations or have huge datasets, you may which to avoid Docker. In this case, you need:

* PHP 8.4+ (CLI-only is fine)
* MariaDB & its PDO driver for PHP (or whichever databases your platforms require)
* 256MB `memory_limit` for PHP (Nitro Porter will attempt to do this automatically)

You can optionally follow my [PHP localhost guide for Mac](https://lincolnwebs.com/php-localhost/).

With a configured localhost, then:

1. [Get Composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos).
1. Make sure Composer is [in your PATH](https://www.uptimia.com/questions/how-to-add-composervendorbin-to-your-path).
1. `composer global require "prosembler/nitro-porter"`.
1. Go to `prosembler/nitro-porter` within your Composer directory.
   1. To do this on MacOS: `cd ~/.composer/vendor/prosembler/nitro-porter`
1. Copy `config-sample.php` as `config.php`. 

## Basic Usage

1. Add connections for your source and output to `config.php`.
1. See the options with `porter --help`.

It's normal for a migration to take a while. You're learning a new tool, and you might find bugs from edge cases in you content or more recent changes in the source or target software. 
If you want free help, expect the back-and-forth to potentially take months depending on the scope of the issues and volunteer availability.
If you're in a hurry, contract a developer to manage the process for you. As usual, mind the axiom: "You can have it fast, good, or cheap — pick 2."

### Get oriented

Get the "short" names of the packages and connections you want to use.

Run `porter list` and then choose whether to list:
* sources [`s`] — Package names you can migrate from
* targets [`t`] — Package names you can migrate to
* connections [`c`] — What's in your config (did you make one?)

Note the bolded values without spaces or special characters. Those are the `<name>` values you need next.

### Check support

What can you migrate? Find out!

Run `porter show source <name>` and `porter show target <name>` to see what feature data is supported by the source and target. Data **must be in both** for it to migrate.

### Optional: Install the target software

Nitro Porter tends to work the smoothest when you pre-install the new software so its database tables preexist when running the migration. However, it should also work without doing this, so keep reporting issues in either scenario.

### Run the migration

Use `porter run --help` for a full set of options (including shortcodes).

A very simple run might look like: 
```
porter run --source=<name> --input=<connection> --target=<name>
```

**Example A**: Export from Vanilla in `example_db` to Flarum in `test_db`:
```
porter run --source=Vanilla --input=example_db --target=Flarum --output=test_db
```

**Example B**: Export from XenForo in `example_db` to Flarum in the same database, using shortcodes:
```
porter run -s Xenforo -i example_db -t Flarum
```

## Advanced Usage

The File Transfer tool (new in 4.0) enables moving files (like attachments & avatars) between platforms by renaming & copying them.
To use it, set the following fields in your `config.php`:

* `source_root` is installation folder of the platform you're migrating away from.
* `target_root` is the local installation folder to copy files into.
* `target_webroot` is the folder under the webroot the platform is installed under when live (if any).

If `source_root` & `target_root` are set, Nitro Porter will evaluate whether the source & target support file transfer.
As of 4.0, only Xenforo -> Flarum is supported as a proof of concept.

## Troubleshooting

### Command 'porter' not found

Verify Composer is in your PATH with `echo $PATH`. On MacOS, you should see `/Users/{username}/.composer/vendor/bin` in there somewhere.

### Follow the logs

Nitro Porter logs to `porter.log` in its installation root (e.g. `~/.composer/vendor/prosembler/nitro-porter` on MacOS). Open it with your favorite log viewer to follow along with its progress.

### Database table prefixes

Try using the same database as both source & target. Nitro Porter works well with multiple platforms installed in the same database using unique table prefixes.

Currently, it **can only use the system default table prefix for targets**, but you can customize the source prefix. It uses `PORT_` as the prefix for its intermediary work storage. You can safely delete the `PORT_` tables after the migration.
