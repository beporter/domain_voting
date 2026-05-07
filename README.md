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

* We've reached a point in software engineering where the majority of engineers are completely accustomed to 'complicated' tech stacks. [Composer](https://getcomposer.org/), [NPM](https://www.npmjs.com/), [Docker Compose](https://docs.docker.com/compose/), [install-php-extensions](https://github.com/mlocati/docker-php-extension-installer), [Kubernetes](https://kubernetes.io/), [Terraform](https://developer.hashicorp.com/terraform)-- the list is nearly infinite.
* All of these layers are justified for large projects, running at Google or Facebook scale.
* But I think we jump to them too quickly, and just accept the pain and suffering they always incur without enjoying the benefits that make them worth the pain at scale.
* So this script has zero build steps, zero PHP extension or Composer package dependencies, zero devops steps, a single "CI" script, and a single step deploy process involving a single file.
* It's still fully unit tested, conforms to the PHP-FIG's [PER-3 standard](https://www.php-fig.org/per/coding-style/), is fully type hinted, and avoids the [OWASP Top 10](https://owasp.org/www-project-top-ten/).
* So my question to readers is: _Which 'best practices' actually matter for the smallest of projects, and which are [premature optimization](https://en.wikipedia.org/wiki/Program_optimization#When_to_optimize)?_

## Requirements

* A web server running PHP v8.5+.
* An optional [Porkbun](https://porkbun.com) account for availability and pricing lookup.

## Support

* Open a [GitHub Issue](https://github.com/beporter/domain_voting/issues). (No guarantees on availability to respond.)

## Developer Setup

* Install PHP v8.5+ (Ex: `brew install php`)
* Use the included `./vothing.sh` wrapper script to launch a local copy of the script.
* Visit the local URL listed.

## Development

* Use `./test.sh` to run a syntax check, static analysis, code sniffing, and unit tests.
* Open a [pull request](https://github.com/beporter/domain_voting/pulls) if you want, but remember the design philosophy! I won't be accepting refactors that introduce composer or additional complication without a well justified explanation of the benefits and trade offs.

## Semi-automated deployment

1. Create an ssh config block for your web server.

    ```conf
    # (Edit this block for your needs and add it to your `~/.ssh/config` file.
    #  Test that running `sftp voting-server` works.)
    Host voting-server
        HostName ip.or.domain.name
        Port 22
        User your_user
        #IdentityFile ~/.ssh/id_ed25519
    ```

1. Ensure your local `.env` has the `REMOTE_WEBROOT` and `PUBLIC_WEBROOT` values set correctly.
1. Run `./deploy.sh`.

## Semi-automated availability updates

If your webhost is particularly slow, or strictly limits the total execution time, you may need to trigger availability and pricing updates repeatedly. This loop can run on a command line and will (eventually) get through `4 * 30` domains in the list.

```shell
source .env
for i in {1..30}; do
    echo "Loop #$i";
    curl -v \
        -d '' \
        -A 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:140.0) Gecko/20100101 Firefox/140.0' \
        -H 'Accept-Encoding: gzip, deflate' \
        -H 'Accept-Language: en-US,en;q=0.5' \
        -H 'Referer: ${PUBLIC_WEBROOT}/voting.php?action=update_availability' \
        "${PUBLIC_WEBROOT}/voting.php?action=update_availability" ;
        echo "Sleeping 1 min...";
        sleep 60;
done
```

## TODO

* `deploy.sh` should use `envsubst` to populate `example-nginx.conf` and `example.htaccess` into `tmp/` with values injected from `.env`.

## License

Undecided

## Copyright

&copy; 2026 Brian Porter
