# Transferring data

## Basic features

Nitro Porter makes some basic assumptions about the nature of data being migrated.

* Folks have made an individual user account.
* Folks probably have some sort of role (or group) that may grant abilities.
* Users are communicating in text via a comment (or message / post).
* Comments are organized into discussions (or topics /  threads).
* Discussions are organized into categories (or channels / subforums / groups / tags).

Therefore, all sources & targets support migrating:

* users
* roles
* categories
* discussions
* comments

Those are the standard words Porter uses for clarity across systems. 
All Source & Target packages expect methods with those names (exactly) and run them in that order.

## Advanced features

Beyond that, each [package]((https://nitroporter.org/domain) supports **different types of data** 
depending on feature availability, extension choice, and maturity of the source/target package.
These include things like badges, reactions, bookmarks, and polls. 
**_Both the source and target must support a data type for it to transfer!_**

## Special data

Special types of data introduce a number of complications.

### Permissions

Nitro Porter **never** transfers permissions. It's not safe to do so automatically due to variations in how platforms implement them.
You will **always** need to reassign permissions after a migration.

### Passwords

**Passwords** are generally _hashed_, which means no system can "decrypt" or "convert" them. 
However, if both the source and target platform support the same hashing algorithm, they should transfer seamlessly. 
Alternatively, the target system could add support for the source hashing algorithm and convert password hashes as users login next (see [Garden/Password](https://github.com/prosembler/garden-password)). This is beyond the scope of what any migration tool can do in isolation, but we're happy to [answer questions](https://github.com/prosembler/nitro-porter/discussions/new) about the process should you wish to build that functionality.

## Supported data formats

Nitro Porter requires access to a MariaDB/MySQL database and uses it for converting between other storage formats as needed. 

Some storage formats first require conversion to a relational database with other software outside Nitro Porter.
These include specially-formatted flat files (like mbox or XML) or different flavors of RDMS, API or NoSQL that we don't support yet.

For example, as of this writing:

* ASPPlayground's Source requires you have already converted from MSSQL to MySQL.
* mbox's Source requires you have parsed & ingested its files into MySQL.

However, Nitro Porter now natively supports:

* Using PostgreSQL as the Target for Discourse.
* Using MongoDB as the Target for NodeBB.
* Using a custom API as the Origin for Discord.

The 3.0 rewrite of Nitro Porter was done with future support for many more formats in mind and it will continue to expand.

### Contributing support

We're happy to accept **contributions** of a new Source, Target, or Origin for any publicly available software.
