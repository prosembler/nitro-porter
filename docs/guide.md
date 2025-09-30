# User Guide

## Requirements

You need:

* PHP 8.3+
* MariaDB (or whichever databases your platforms require)
* PHP's PDO driver for your data sources (probably MySQL or PostgreSQL).
* 256MB of memory allocated to PHP

Nitro Porter will set PHP's memory limit to 256MB. If it's unable to do so, it may suffer performance issues or generate errors. For small forums, you may be able to safely reconfigure it to 128MB or lower.

A quick way to get all of the above would be installing MAMP or XAMPP on your laptop. The longer way, if you're doing this often or have huge datasets, is to follow my [PHP localhost guide for Mac](https://lincolnwebs.com/php-localhost/).


## Installation

1. [Get Composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos).
1. Make sure Composer is [in your PATH](https://www.uptimia.com/questions/how-to-add-composervendorbin-to-your-path).
1. `composer global require "prosembler/nitro-porter"`.
1. Go to `prosembler/nitro-porter` within your Composer directory.
   1. To do this on MacOS: `cd ~/.composer/vendor/prosembler/nitro-porter`
1. Copy `config-sample.php` as `config.php`. 
1. Add connections for your source and output to `config.php`.
1. See the options with `porter --help`.

## Basic Usage

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
