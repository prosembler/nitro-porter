## How to Contribute to Nitro Porter

In ascending order of effort:

### Send data!

We greatly appreciate donated data from existing forums to improve the migration and its testing (using partial, anonymized records). A complete database dump is best way to do this. We protect privacy, but you're welcome to anonymize personally-identifiable information first. Willing to sign an extremely narrow NDA for the purpose if necessary. Contact lincoln@icrontic.com.

### Report a problem

[Start a discussion](https://github.com/prosembler/nitro-porter/discussions/new) if you've hit a problem, including as much detail as possible and any error message or logs available. We don't currently maintain a formal issue tracker.

### Report a _success_!

Did you successfully use Nitro Porter? [Start a discussion](https://github.com/prosembler/nitro-porter/discussions/new) to tell us all about it! What platforms were you migrating between and how much data was involved? Was it confusing at all?

### Submit a code fix or improvement

Before sending a pull request with a proposed fix, please *document your understanding* of the change in the description.
This project supports learning, so asking questions is also great. All of this makes review much easier!

Please remember maintaining this code is not a job, nor is it anyone's duty to accept submitted code.
We want to talk to other humans, not review generated slop because a bot said it was good.

It would be lovely if you used the PSR-12 coding style, matched our other conventions, ran PHPStan, and added tests.
You could similarly try using [conventional commits](https://www.conventionalcommits.org) for a nicer changelog.
But if you don't know what any of that means, this is still a good place to ask & learn about it.

### Add support for a new source or target

Check the [developer guide](https://nitroporter.org/develop) for info on extending Nitro Porter to support a new source or target.
It's pretty straightforward! We'll help clean it up if you run into challenges, just get the first draft up in a PR.

### Work on core maintenance

Check the [maintainer guide](https://nitroporter.org/maintain) for doing advanced work on the core of this project.
It currently needs integration tests setup and could use a number of additional database connectors configured.
More aspirations are articulated in the [informal roadmap](https://github.com/orgs/prosembler/projects/1/views/1).
If you're taking on this level of work there are higher expectations, but Linc is happy to help guide.
