Nitro Porter — free your community! 🚀
==============

Nitro Porter is the _only_ multi-platform community migration tool. 
Its goal is 1-hour no-code data migrations for any community with accessible data.

Every community deserves the best software for its mission while preserving its unique history.
Platform lock-in stifles competition and all proprietary software has a finite lifespan.
When everyone owns their own data and can freely choose their platform, everyone wins.

## Get Started

* [**User Guide**](https://nitroporter.org/guide) — requirements & install steps.
* [**Migration Guide**](https://nitroporter.org/migrations) — plan a community migration.
* [**Sources**](https://nitroporter.org/sources) & [**Targets**](https://nitroporter.org/targets) — support details.
* [**Start a Discussion**](https://github.com/prosembler/nitro-porter/discussions) — share how it went!

## What's Supported?

### Targets ([4](https://nitroporter.org/targets)) 📥

![Flarum](docs/assets/logos/flarum-300x100.png)
![NodeBB](docs/assets/logos/nodebb-300x100.png)
![Vanilla](docs/assets/logos/vanilla-300x100.png)
![Waterhole](docs/assets/logos/waterhole-300x100.png)

### Sources ([37](https://nitroporter.org/sources)) 📤

![AnswerHub](docs/assets/logos/answerhub-150x50.jpg)
![ASPPlayground.NET](docs/assets/logos/aspplayground-150x50.png)
![bbPress](docs/assets/logos/bbpress-150x50.png)
![Discord](docs/assets/logos/discord-150x50.png)
![Drupal](docs/assets/logos/drupal-150x50.jpeg)
![esoTalk](docs/assets/logos/esotalk-150x50.png)
![Flarum](docs/assets/logos/flarum-150x50.png)
![FluxBB](docs/assets/logos/fluxbb-150x50.png)
![IPBoard](docs/assets/logos/ipboard-150x50.png)
![Kunena](docs/assets/logos/kunena-150x50.jpg)
![MyBB](docs/assets/logos/mybb-150x50.png)
![NodeBB](docs/assets/logos/nodebb-150x50.png)
![phpBB](docs/assets/logos/phpbb-150x50.png)
![Simple Machines (SMF)](docs/assets/logos/smf-150x50.jpeg)
![SimplePress](docs/assets/logos/simplepress-150x50.png)
![Uservoice](docs/assets/logos/uservoice-150x50.jpeg)
![Vanilla](docs/assets/logos/vanilla-150x50.png)
![vBulletin](docs/assets/logos/vbulletin-150x50.jpeg)
![XenForo](docs/assets/logos/xenforo-150x50.jpeg)

_...[and MORE](https://nitroporter.org/sources)!_

### What data gets migrated, exactly? 🚥

All sources & targets support migrating:
* users & roles
* discussions (or _threads_)
* posts (or _comments_)
* categories (or _subforums_, _channels_, etc.)

Beyond that, each supports **different types of data** depending on feature availability, extension choice, and maturity of the source/target package.
These include things like badges, reactions, bookmarks, and polls.

**_Both the source and target must support a data type for it to transfer!_**

Nitro Porter **never** transfers permissions. It's not safe to do so automatically due to variations in how platforms implement them.
You will **always** need to reassign permissions after a migration.

**Passwords** are generally _hashed_, which means no system can "decrypt" or "convert" them. However, if both the source and target platform support the same hashing algorithm, they should transfer seamlessly. Alternatively, the target system could add support for the source hashing algorithm and convert password hashes as users login next (see [Garden/Password](https://github.com/prosembler/garden-password)). This is beyond the scope of what any migration tool can do in isolation, but we're happy to [answer questions](https://github.com/prosembler/nitro-porter/discussions/new) about the process should you wish to build that functionality.

### Future support 🔭

Don't see your software? [Start a discussion](https://github.com/prosembler/nitro-porter/discussions/new) to request it and keep an eye on our [informal roadmap](https://github.com/orgs/prosembler/projects/1).
We're happy to accept **contributions** of a new Source, Target, or Origin for any publicly available software.
We occassionally accept **requests** for a new Source or **sponsorships** for any package type.

Today, some storage formats (e.g. mbox, MSSQL, API) first require conversion to a relational database with other software. 
The 3.0 rewrite of Nitro Porter[^1] was built with future support for those many more formats in mind and it will continue to expand.

## Project Info

### How does it work? 🤔

Data is first converted to an intermediary "porter schema," reducing the number of data paths from `#sources x #targets` to `#sources + #targets`.
The result is repeatable results in a single multi-tool rather than myriad low-quality, single-purpose tools.

### Why not 1-off migration tools? 🪴

Data migrations typically require either time & skill (you are a programmer) or capital (you are a for-profit company).
Nitro Porter aims to become increasingly accessible to _everyone else_ by making both migrations & extensibility simple.
Software tools should not be assumed disposible just because they don't have a 30% profit margin to exploit.

### Can't AI do this? 📿

No.

### How can I learn more or help? 🎟️

* [**Contribute**](docs/contribute.md) — data, requests, & fixes.
* [**Changelog**](CHANGELOG.md) — latest fixes & updates.
* [**Roadmap**](https://github.com/orgs/prosembler/projects/1) — informal goals.
* [**History**](docs/history.md) — how we got here.

[^1]: 🚀 Forked 27 Sep 2021 in memory of Kyle
