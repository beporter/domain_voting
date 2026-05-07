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

* Install PHP v8.5+ (Ex: `brew install php`)
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

```conf
# (Edit this block for your needs and add it to your `~/.ssh/config` file.
#  Test that running `sftp voting-server` works.)
Host voting-server
    HostName ip.or.domain.name
    Port 22
    User your_user
    #IdentityFile ~/.ssh/id_ed25519
```

If your webhost is particularly slow, or strictly limits the total execution time, you may need to trigger availability and pricing updates repeatedly. This loop can run on a command line and will (eventually) get through `4 * 30` domains in the list.

```shell
for i in {1..30}; do
    echo "Loop #$i";
    curl -v \
        -d '' \
        -A 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:140.0) Gecko/20100101 Firefox/140.0' \
        -H 'Accept-Encoding: gzip, deflate' \
        -H 'Accept-Language: en-US,en;q=0.5' \
        -H 'Referer: https://YOUR.DOMAIN.HERE/voting.php?action=update_availability' \
        'https://YOUR.DOMAIN.HERE/voting.php?action=update_availability' ;
        echo "Sleeping 1 min...";
        sleep 60;
done
```

## License

Undecided

## Copyright

&copy; 2026 Brian Porter
