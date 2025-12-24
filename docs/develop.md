# Developer Guide

Nitro Porter works in this order:

1. The **Source** package translates the data to the intermediary "porter format" (see below). These are new database tables with the prefix `PORT_`.
2. The **Target** package translates the data to the final platform format. These can be existing tables from an installation, or it will create them new using the information provided.
3. If a **Postscript** file with the same name as the Target exists, it runs last. This is for doing calculations that require the data to have been fully transferred already, for example generating data that wasn't ever in the Source.

The `Migration` is a process model that gets passed between every step in the process. It's instantiated as `$port` throughout the code.

Use its `comment()` method for logging. Open `porter.log` in your favorite log reader for realtime feedback.

## Porter Format

Nitro Porter uses a "porter format" roughly analogous to the database design of Vanilla Forums. 
That means all sources translate to Vanilla, and all targets translate from Vanilla. Doing this alleviates multiple challenges.

First, imagine 50 sources and 50 targets. Direct migrations would create exponential complexity (50:50 = 2500 possible paths).
By using a dedicated intermediary, complexity is significantly constrained (50:1 and 1:50, so that only 100 paths are possible).

Second, many forum database designs are difficult to interpret and/or very strict in their data structure. 
Vanilla's is fairly sensible and serves as a good reference. It was also designed for easy import.

Third, Nitro Porter's origin is as a Vanilla migration tool, so it preserves backwards compatibility for the original sources.

### Considerations regarding Porter Format

One common issue with this Porter Format is that the original post's body is attached directly to the discussion record.
A majority of forums instead associate a generic post/comment record as the "first", and the discussion record contains only the title. 
Nitro Porter uses the `getDiscussionBodyMode()` method to skip the overhead of doing this conversion if both the source and target use this alternative structure.

Private messages in Vanilla function as a discussion with an allowlist of participants. 
There is no consideration of when a user was added to a private message chain. It does not support PM organization in any way.


## Add a new Source

**New sources will be automatically detected at runtime and added as options.**

1. Copy and rename `src/Source/Example.php`.
2. Edit the `SUPPORTED` data array, following the inline comments.
3. The basic types of data are stubbed out, one per method. Follow the inline docs.

Source packages are invoked by their `run()` method. It must call any methods you add in the order you want.

Typically, Sources do NOT reformat user generated content (UGC) and simply label how it is formatted on the relevant records (e.g. setting `Comment.Format` to 'BBCode' or 'HTML').

### Maps and filters

Use a `$map` array to directly translate a column name in one database to another. Need the data transformed? You can use a function. Pass an array like `['Column' => 'Name', 'Filter' => 'HTMLDecoder']` and the `Name` column's value will be passed to the function `HTMLDecoder()` (along with the rest of the data in the row) for manipulation and the return value will be stored instead of the original. Use the `src/Functions/filter.php` for adding new filters.

### Using export()

The `Migration::export()` call is what does the data transfer. The `$query` parameter must at least select all the columns in the `$map` array for it to work. Use the table prefix `:_` for it to be dynamically replaced per the user's input.

### Requiring tables and columns

You can use the `$sourceTables` property to require certain tables and columns in the source database, but it's optional.

## Add a new Target

1. Copy and rename `src/Target/Example.php`.
2. Edit the `SUPPORTED` data array, following the inline comments.
3. The basic types of data are stubbed out, one per method. Follow the inline docs.

Target packages first have their `validate()` method called, then their `run()` method.

To confirm an optional data type exists before importing it, use `targetExists()` on the `Migration`.

It is often necessary to reformat user generated content (UGC), like comments, during the import. See the `Formatter` class.

### Verify source support for each feature

It's not safe to assume every `PORT_` table will be present because not all source packages provide all types of feature data.

These tables should always be present: `PORT_User`, `PORT_Discussion`, `PORT_Comment`, and `PORT_Category`.

For all others, check if the table exists. Example:

```php
        // Verify support.
        if (!$ex->targetExists('PORT_FeatureName')) {
            $ex->comment('Skipping import: FeatureName (Source lacks support)');
            return;
        }
```

Generally tables come in bundles. For instance, there's little use for `PORT_Role` if `PORT_UserRole` is not also present. Checking for one is usually sufficient.

### Using import()

The `import()` method works a bit more cleanly than the `export()` method. It takes a map array, but also accepts a built SQL statement rather than a query string. It requires defining the target database table structure, and separates filters into their own array for clarity. Use `$ex->dbImport()` to build the SQL statement.

If a column isn't present in the structure passed to `import()`, it will be ignored entirely.

### Using a Postscript

Simply add a new PHP class to the `src/Postscript` folder with the same name as the Target and a method named `run()`. It will automatically run after the import. Its `storage` property will get set with the database connection automatically.

## Writing integration tests

### Create a schema migration

Refer to the [Phinx docs](https://book.cakephp.org/phinx/0/en/index.html) for creating schema migrations for integration tests.

To create a schema migration from an existing database (rather than hand-coding it from scratch), you need to also use Cake.
(This works because Phinx is a spin-off project from Cake, so the framework creates compatible migrations in this scenario.)

1. Create a new Cake project: `composer create-project --prefer-dist cakephp/app:~4.0 some_folder`
1. Add the database creds in: `path_to/some_folder/config/app_local.php`
1. Run a Cake snapshot: `path_to/some_folder/bin/cake bake migration_snapshot SomeName`
1. Copy the new file created in `path_to/some_folder/config/Migrations` to Porter's test folder structure in `tests/integration/migrations/{PackageName}`.

From there, you can edit it and refer to it like other integration tests.

## Working with database connections

Nitro Porter uses the [Laravel Illuminate](https://github.com/illuminate/database) database driver. Refer to its [documentation](https://laravel.com/docs/9.x) for help.

While Nitro Porter reuses an existing database connection wherever possible, it defaults to using an unbuffered query for speed, and it will often be advisable to use the driver's `cursor()` method to stream the results.

You need a second, separate database connection to do other queries while unbuffered results are streaming. The streaming connection is effectively mid-query. While this rarely comes up in **Source** packages since they are simply dumping information and the **Target** package usually abstracts away much of this work, it can get tricky when complex **Postscript** operations are necessary. Refer to the `Flarum` Postscript for examples.


## Non-MySQL help

### MSSQL conversions

If you need to migrate from MSSQL with a `.bak` file (e.g. from AspPlayground) and you're working on an M1 Macbook Pro, [this guide will help](https://lincolnwebs.com/mssql-macos/).
