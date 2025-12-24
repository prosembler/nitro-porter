# History of Nitro Porter

Nitro Porter was created in 2010 at Vanilla Forums, Inc. as "Vanilla Porter".
Originally designed as a single-file "lifeboat" for rescuing forum data on older webservers, it morphed into an efficient CLI-based export tool.

It was frequently over-engineered to bring as much data as possible and to prove what was possible, not just check a box,
because the team believed in the importance of community history.

The first several versions only exported to the Vanilla flat file format, which then Vanilla core would import and do final data manipulations.
Lincoln built the first source package for vBulletin, and several other engineers (both internal to Vanilla and OSS contributors) built additional packages.

Over a decade, export ("source") support was added for many forum packages, eventually exceeding thirty platforms.
It was even used by a Vanilla competitor for its ability to export reliably from so many systems.

This unique history allowed hundreds of small details to accumulate in the logic, making it not only irreplaceable,
but a _knowledge_ repository as much as a code one. Nitro Porter doesn't just contain a set of simple data mappings,
it contains a detailed record of how Web software databases evolved over decades and accounts for each change in detail.

By late 2019, Vanilla had ceased creating packaged open source releases and Vanilla Porter was receiving only minimal updates.

On 27 September 2021, [Lincoln](https://lincolnwebs.com/about/) forked the project as Nitro Porter and rebuilt it into a general-purpose migration pipeline.
It continues to use Vanilla's database schema as an intermediary format to allow backwards compatibility with the source packages already created.

In 2022, this documentation site launched and Flarum was added as the second available target.
Source support was added for Flarum, Kunena, MVC and Q2A. ASP Playground source support was improved.
Source support for Vanilla's Advanced Editor (Quill-based WYSIWYG) was added.

From 2023-24, Waterhole was added as a target. Source support for IPB4 was added. Nitro Porter got enhanced CLI support and went CLI-only
(removing the Web GUI). And, the [maintainer guide](/maintain) was created.

In Aug-Sep 2025, the [4.0 release](https://github.com/prosembler/nitro-porter/releases/tag/v4.0) brought 
testability through Phinx & dependency injection, file transfer support (beginning with Xenforo -> Flarum),
improved config & CLI options, improved logging, and completed integration of Laravel's database library.
It also advanced work on the [Domain Model](https://nitroporter.org/domain.html#domain-diagram) & architecture
to make connection handling & the overall workflow clearer.
