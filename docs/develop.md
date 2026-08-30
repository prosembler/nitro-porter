# Developer Guide

Porter's packages are the answer to the question "How does your app's schema relate to our reference schema?"
The goal is to describe that relationship with as little raw SQL and custom scripting as possible.

Nitro Porter has three primary functions:

0. Pull: An **Origin** package pulls data from schemaless data connection (like an API) and coerces the data into a basic schema.
1. Export: A **Source** package translates a schema into the intermediary reference schema (see below). These are relational database tables with the prefix `PORT_`.
2. Import: A **Target** package translates the reference schema to the final platform's schema. These can be existing tables from an installation, or it will create them new using the information provided.
If a **Postscript** file with the same name as the Target exists, it runs last. This is for doing calculations that require the data to have been fully transferred already, for example generating data that wasn't ever in the Source.

## Porter's Reference Schema

Nitro Porter uses a reference schema roughly analogous to the database design of Vanilla Forums. 
All sources translate into this schema, and all targets translate from it. Doing this alleviates multiple challenges.

First, imagine 50 sources and 50 targets. Direct migrations would create exponential complexity (50:50 = 2500 possible paths).
By using a dedicated intermediary, complexity is significantly constrained (50:1 and 1:50, so that only 100 paths are possible).

Second, many forum database designs are difficult to interpret and/or very strict in their data structure. 
Vanilla's is fairly sensible and serves as a good reference. It was also designed for easy import.

Third, Nitro Porter's origin is as a Vanilla migration tool, so it preserved backwards compatibility for the original sources.

### Considerations regarding Porter Format

One common issue with this Porter Format is that the original post's body is attached directly to the discussion record.
A majority of forums instead associate a generic post/comment record as the "first", and the discussion record contains only the title. 
Nitro Porter uses the `getDiscussionBodyMode()` method to skip the overhead of doing this conversion if both the source and target use this alternative structure.

Private messages in Vanilla function as a discussion with an allowlist of participants. 
There is no consideration of when a user was added to a private message chain. It does not support PM organization in any way.

## Basic concepts

### Schemas

Porter's schemas are RDBMS-compatible table definitions using PHP arrays, as defined in Laravel's Illumimnate.
Column names are keys, and their column types are the value of each array. 
A special 'keys' key may define indices for the table.

### Maps

Maps define 1:1 column name relationships between schemas using PHP arrays. 
The key is always the current column name, and the value is the destination column name.
Even if the data is represented differently (e.g. 0/1 vs false/true), use a map to define their relationship.

### Filters

Filters are data transformations for individual values which are assigned with PHP arrays.
Filters are objects under `src/Filters` with property-based access to the current value (if any), column name, and record.

### Query Builder

Porter uses the Laravel Illuminate query builder.
Whenever possible, avoid advanced implementations of the query builder and use Maps & Filters instead.

### Manifest

A manifest describes a series of work steps that may be defined by a package.
If methods with those names are present in a Package, they will be called automatically during the migration.
Currently, there is only one manifest and it lists the steps required to migrate a community.

## Add a Source

**New sources will be automatically detected at runtime and added as options.**

1. Copy and rename `src/Source/ExampleSource.php`.
2. Edit the `SUPPORTED` data array, following the inline comments.
3. The basic types of data are stubbed out, one per method. Follow the inline docs & delete them afterward.

Typically, Sources do NOT reformat user generated content (UGC) and simply label how it is formatted on the relevant records (e.g. setting `Comment.Format` to 'BBCode' or 'HTML').

### Requiring tables and columns

You can use the `$sourceTables` property to require certain tables and columns in the source database, but it's optional.

## Add a Target

1. Copy and rename an existing target in `src/Target/`.
2. Edit the `SUPPORTED` data array, following the inline comments.
3. The basic types of data are stubbed out, one per method. Follow the inline docs.

It is often necessary to reformat user generated content (UGC), like comments, during the import. See the `Formatter` class.

### Verify source support for each feature

It's not safe to assume every `PORT_` table will be present because not all source packages provide all types of feature data.

These tables should always be present: `PORT_User`, `PORT_Discussion`, `PORT_Comment`, and `PORT_Category`.

For all others, check if the table exists before using it.

Generally tables come in bundles. For instance, there's little use for `PORT_Role` if `PORT_UserRole` is not also present. Checking for one is usually sufficient.

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

You need a second, separate database connection to do other queries while unbuffered results are streaming. The streaming connection is effectively mid-query.

## Non-MySQL help

### MSSQL conversions

If you need to migrate from MSSQL with a `.bak` file (e.g. from AspPlayground) and you're working on an M1 Macbook Pro, [this guide will help](https://lincolnwebs.com/mssql-macos/).
