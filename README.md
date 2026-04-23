# Domain Voting Script

A single-file PHP script for voting on domain name suggestions.

Features:

* Simplest possible install--
  * Set some optional configuration variables if you want.
  * Upload the .php file to a web server with PHP v8.5+.
  * Visit the public URL.
* Allows defining a set of "prefixes", "keywords", "suffixes" and preferred <abbr title="top level domains">TLDs</abbr> in a ranked preference order for each. (Which helps find a domain using a variation of your key idea that isn't already claimed.)
* Uses those components to generate random combinations and presents them for either/or ([ELO](https://en.wikipedia.org/wiki/Elo_rating_system)) voting.

Philosophy:

* We've reached a point in software engineering where the majority of engineers are completely accustomed to 'complicated' tech stacks. [Composer](), [NPM](), [Docker Compose](), [install-php-extensions](), [Kubernetes](), [Terraform]()-- the list is nearly infinite.
* All of these layers are justified for large projects, running at Google or Facebook scale.
* But I think we jump to them too quickly, and just accept the pain and suffering they always incur without enjoying the benefits that make them worth the pain at scale.
* So this script has zero build steps, zero PHP extension or Composer package dependencies, zero devops steps, a single "CI" script, and a single step deploy process involving a single file.
* It's still fully unit tested, conforms to the PHP-FIG's [PER-3 standard](), is fully type hinted, and avoids the [OWASP Top 10]().
* So my question to readers is: _Which 'best practices' actually matter for the smallest of projects, and which are [preemptive optimization]()?_

## Requirements

* A web server running PHP v8.5+.
* An optional [Porkbun](https://porkbun.com) account for availability and pricing lookup.

## Setup

* Use the included `vothing.sh` wrapper script to launch a local copy of the script.
* Visit the local URL listed.

## Support

* Open a [GitHub Issue](). (No guarantees on availability to respond.)

## Development

* Use `test.sh` to run a syntax check, static analysis, code sniffing, and unit tests.

## TODO

* Fill in empty README link URLs.
* Set up example.env.
* Adapt voting.sh to use an .env file.
* Write test.sh to download phpunit.phar and use an .env file.
* Update upload.sh to use .env and take an ssh host target. Document ssh config.
* Modify voting.sh to call out to test.sh.
* Get bash scripts to auto-fetch their dependent `.phar`s when not present in `tmp/`.

## License

Undecided

## Copyright

&copy; 2026 Brian Porter
