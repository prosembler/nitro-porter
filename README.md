Nitro Porter — free your community! 🚀
==============

Nitro Porter is the _only_ multi-platform community migration tool.
Its goal is fast no-code migrations for any community with accessible data.

Every community deserves the agency to choose the best software for its mission while preserving its history.
All proprietary software has a finite lifespan and lock-in stifles competition, which leads to stagnation. 
When we own our data and can freely choose betweeen platforms, everyone wins.

## Get Started

Working through a migration often takes weeks, not hours! Set reasonable expectations and be patient.

* [**User Guide**](https://nitroporter.org/guide) — requirements & install steps.
* [**Migration Guide**](https://nitroporter.org/migrations) — plan a community migration.
* [**Sources**](https://nitroporter.org/sources) & [**Targets**](https://nitroporter.org/targets) — support details.

We greatly appreciate [feedback](https://github.com/prosembler/nitro-porter/discussions)! However, we check in periodically as our schedule allows, not daily.

## What Can It Do?

Migrate a community from a supported Source to a supported Target as comprehensively as possible.

### Supported Targets (where you're going) — [4 total](https://nitroporter.org/targets) 📥

[![Flarum](docs/assets/logos/flarum-300x100.png)](https://flarum.org)
[![NodeBB](docs/assets/logos/nodebb-300x100.png)](https://nodebb.org)
[![Vanilla](docs/assets/logos/vanilla-300x100.png)](https://github.com/prosembler/vanilla)
[![Waterhole](docs/assets/logos/waterhole-300x100.png)](https://waterhole.dev)

### Supported Sources (what you're using now) — [37 total](https://nitroporter.org/sources) 📤

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

Don't see your software? [Start a discussion](https://github.com/prosembler/nitro-porter/discussions/new) to request it and check our [informal roadmap](https://github.com/orgs/prosembler/projects/1).

### What gets migrated, exactly? 🚥

All sources & targets support migrating:
* users & roles
* discussions (or _threads_) & posts (or _comments_)
* categories (or _subforums_, _channels_, etc.)

Beyond that, each package as different support based on feature availability, extension choice, and maturity of the package.
These include things like badges, reactions, bookmarks, & polls. Both source and target must support a data type for it to transfer.
Read more about [how data is transferred](https://nitroporter.org/data).

## Project Info

### How does it work? 🤔

Data is first converted to an intermediary "porter schema," reducing the number of data paths from `#sources x #targets` to `#sources + #targets`.
The result is repeatable results in a single multi-tool rather than myriad low-quality, single-purpose tools.

### Why not use 1-off migration tools? 🪴

Data migrations typically require either time & skill (you are a programmer) or capital (you are a for-profit company).
Nitro Porter aims to become increasingly accessible to _everyone else_ by making both migrations & extensibility simple.
Software tools should not be assumed disposible just because they don't have a 30% profit margin to exploit.

### Can't AI do this? 📿

No.

### Will you do it for me? 🙏

Rarely. It's more effective to work through it yourself, ask questions, and file tickets if you confirm a bug.
Feedback on how the tool works for you (or doesn't) is very valuable and we're not running a migration business.

We occassionally accept **requests** for a new Source or **sponsorships** for any package type, and we do
have a cost estimation guide if you really must outsource it. Contact migrations@prosembler.com.

### How can I learn more or help? 🎟️

* [**Contribute**](docs/contribute.md) — data, requests, & fixes.
* [**Changelog**](CHANGELOG.md) — latest fixes & updates.
* [**Roadmap**](https://github.com/orgs/prosembler/projects/1) — informal goals.

### Where did this come from? 📚

Vanilla Porter was created in 2010 at Vanilla Forums as a single-script multi-source export tool, abandoned by 2020.
Nitro Porter 3.0 was a rewrite[^1] that preserved compatibility with existing sources while reimagining the framework.
In 2026, the [Open Social Fund](https://nlnet.nl/opensocial), a fund established by [NLnet](https://nlnet.nl/), 
sponspored major development work in the 5.0 release. [More history on our website](docs/history.md).

[^1]: 🚀 Forked 27 Sep 2021 in memory of Kyle
