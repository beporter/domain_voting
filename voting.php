<?php
declare(strict_types=1);

/**
 * Domain Suggestion and Voting Script
 *
 * Created as a part of the search for a Burglin production domain,
 * itself a part of the AuctionPub project.
 *
 * Deploying:
 *
 *     - Review and tune the `define()`s below.
 *     - Then see: `deploy.sh`.
 *
 * To run/test locally:
 *
 *     - See `voting.sh`.
 *
 * Unit tests & Static analysis:
 *
 *     - See `test.sh`.
 *
 * @author Brian Porter <beporter@users.sourceforge.net>
 * @copyright 2026 Brian Porter
 * @license proprietary
 * @version 1.0.0
 * @requires PHP v8.4+, ext-mb_string, ext-sqlite3
 */

namespace Voting;

use \InvalidArgumentException;
use \RuntimeException;
use \SQLite3;
use \SQLite3Stmt;
use \SQLite3Result;
use \StdClass;
use \Stringable;
use \Throwable;
use \Uri\WhatWg\Url;

/**
 * Script config.
 *
 * Prefixes, suffixes and TLDs are hard-coded.
 * Keywords are stored in sqlite.
 *
 * We use plain string formatting instead of an array to make it easy to
 * copy/paste lists of prefixes/suffixes/tld's out of a spreadsheet and
 * in here. For prefixes and suffixes, make sure to include an empty
 * line if you want "no prefix" and/or "no suffix" to show up as an option.
 *
 * All of these lists should be sorted in descending order of preference,
 * with your most-preferred prefix/suffix/tld at the top.
 */
define('PREFIXES', <<<'EOP'

gilded
the
your
my
EOP);

define('SUFFIXES', <<<'EOS'

park
hall
list
bazaar
glen
EOS);

define('TLDS', <<<'EOT'
com
app
art
auction
co
gallery
EOT);

define('DEBUG', true);

define('DB_FILE', getenv('VOTING_DB_PATH') ?: __DIR__ . '/voting.sqlite3');
define('BIAS_EXISTING_PROB', 0.9); // Chance to pick from DB vs random.
define('PAIRWISE_PROB', 0.9); // Chance opponent comes from close ELO match in DB vs random.
// For all of the below:
// * (0 < value <= 1)
// * lower values INCREASE bias towards the trait.
define('ARRAY_WEIGHT_FACTOR', 0.6); // How heavily prefixes/suffixes/tlds are preferred at the top of the lists.
define('VOTE_WEIGHT_FACTOR', 0.85); // How heavily higher vote counts influence random vote selection.
define('ELO_WEIGHT_FACTOR', 0.9); // How heavily higher ELO scores influence random vote selection.
define('YEAR1_WEIGHT_FACTOR', 0.95); // How heavily first-year prices influence random vote selection.
define('RENEWAL_WEIGHT_FACTOR', 0.9); // How heavily renewal prices influence random vote selection.
define('LEADERBOARD_LIMIT', 20);
define('RATE_LIMIT_DEFAULT_SECS', 10);
define('PAGE_TITLE', 'Domain Suggestions & Voting');

// Actual (semi-)secrets.
define('PORKBUN_API_TOKEN', getenv('PORKBUN_API_TOKEN') ?: '');
define('PORKBUN_SECRET_API_TOKEN', getenv('PORKBUN_SECRET_API_TOKEN') ?: '');

/**
 * Debug helper method.
 *
 * Prints all input args in a formatted <pre> tag, and exits the script.
 *
 * @param mixed ...$args
 * @return no-return
 */
function dd(mixed ...$args): void
{
    $block = function ($i, $v) {
        $v = htmlspecialchars($v);
        return <<<EOB
            <h3>Argument [{$i}]</h3>
            <pre><code>{$v}</code></pre>
        EOB;
    };

    $trace = debug_backtrace(0, 2);
    $txt = '';
    $html = '';
    foreach ($args as $i => $a) { // or use `$trace[0]['args']` ?
        $s = var_export($a, true) . PHP_EOL . PHP_EOL;
        $txt .= $s;
        $html .= $block($i, $s);
    }

    $fileLoc = sprintf('%s:%d',
        str_replace(
            __DIR__ . DIRECTORY_SEPARATOR,
            '',
            $trace[0]['file'] ?? '',
        ),
        $trace[0]['line'] ?? '',
    );
    $caller = sprintf('%s%s%s(%s)',
        $trace[1]['class'] ?? '',
        $trace[1]['type'] ?? '',
        $trace[1]['function'] ?? '',
        '', //join(', ', $trace[1]['args'] ?? ''),
    );

    // Always write the error to PHP error log.
    error_log($fileLoc . PHP_EOL . $caller . PHP_EOL . $txt);

    // But only display on-screen and abort the script when DEBUG is enabled.
    if (DEBUG) {
        echo <<<EOM
            <h1>dd() called from {$fileLoc}</h1>
            <h2>in {$caller}</h2>
            {$html}
        EOM;

        exit(1);
    }
}

set_exception_handler(function (Throwable $e) {
    if (DEBUG) {
        dd(
            'Uncaught exception',
            get_class($e),
            $e->getMessage(),
            $e->getTraceAsString(),
        );
    }

    Flash::error($e);
    header('Location: ?action=errors'); // Don't use any other Helpers here.
    exit;
});

/**
 * Thin configuration wrapper for easier management of backend data
 * (constants above).
 */
class Config {
    /**
     * Internal cache for loaded/processed constants.
     *
     * @var array<string, mixed> $config
     */
    private static array $config = [];

    public static function read(string $key): mixed
    {
        if (!array_key_exists($key, self::$config)) {
            self::init($key);
        }

        return self::$config[$key];
    }

    protected static function init(string $key): void
    {
        switch ($key) {
            case 'PREFIXES':
            case 'SUFFIXES':
                $raw = self::loadList($key);
                break;

            // Remove blanks from TLDs.
            case 'TLDS':
                $raw = array_filter(self::loadList($key));
                break;

            default:
                $raw = self::load($key);
                break;
        }

        self::$config[$key] = $raw;
    }

    /**
     * Import a constant into the internal cache.
     *
     * @param string $key
     * @return mixed
     */
    protected static function load(string $key): mixed
    {
        return constant($key);
    }

    /**
     * Import a multi-line string constant into a flat array.
     *
     * @param string $key
     * @return array<string>
     */
    protected static function loadList(string $key): array
    {
        return array_map(
            'mb_trim',
            array_unique(preg_split('/\n/', constant($key)) ?: []),
        );
    }
}

/**
 * Data object for passing around domain details and validating properties.
 */
class Domain implements Stringable
{
    const VALID_HOSTNAME_PATTERN = '^(?:[a-z0-9]{2}|[a-z0-9][a-z0-9-]{1,61}[a-z0-9])$';
    const VALID_TLD_PATTERN = '^(?:[a-z]{2,63})$';

    public function __construct(
        public string $prefix,
        public string $keyword,
        public string $suffix,
        public string $tld,
        public int $vote_count = 0,
        public int $elo_score = 1000,
        public ?bool $available = null,
        public ?float $year1_price = 0,
        public ?float $renewal_price = 0,
        public bool $enabled = true,
        public ?int $id = null,
    ) {}

    /**
     * Create a new Domain entity from the provided associative array.
     *
     * @param array{
     *   prefix?:string,
     *   keyword?:string,
     *   suffix?:string,
     *   tld?:string,
     *   vote_count?:int,
     *   elo_score?:int,
     *   available?:bool|null,
     *   year1_price?:float|null,
     *   renewal_price?:float|null,
     *   enabled?:bool,
     *   id?:int
     * } $data
     * @return Domain
     * @throws InvalidArgumentException
     */
    public static function fromPost(array $data): Domain
    {
        $d = new Domain(
            $data['prefix'] ?? '',
            $data['keyword'] ?? '',
            $data['suffix'] ?? '',
            $data['tld'] ?? '',
            $data['vote_count'] ?? 0,
            $data['elo_score'] ?? 1000,
            isset($data['available']) ? (bool)$data['available'] : null,
            $data['year1_price'] ?? null,
            $data['renewal_price'] ?? null,
            (bool)($data['enabled'] ?? true),
            $data['id'] ?? null,
        );

        if (!$d->isValid()) {
            throw new InvalidArgumentException("Invalid domain: {$d}");
        }

        return $d;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s%s%s.%s',
            $this->prefix,
            $this->keyword,
            $this->suffix,
            $this->tld,
        );
    }

    /**
     * Convert a Domain entity back to an associative array.
     *
     * @return array{
     *   id:int|null,
     *   prefix:string,
     *   keyword:string,
     *   suffix:string,
     *   tld:string,
     *   vote_count:int,
     *   elo_score:int,
     *   available:bool|null,
     *   year1_price:float|null,
     *   renewal_price:float|null,
     *   enabled:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prefix' => $this->prefix,
            'keyword' => $this->keyword,
            'suffix' => $this->suffix,
            'tld' => $this->tld,
            'vote_count' => $this->vote_count,
            'elo_score' => $this->elo_score,
            'available' => $this->available,
            'year1_price' => $this->year1_price,
            'renewal_price' => $this->renewal_price,
            'enabled' => $this->enabled,
        ];
    }

    public function isValid(): bool
    {
        return self::valid($this);
    }

    public static function valid(Domain $d): bool
    {
        return self::validHostname($d)
            && self::validTld($d)
            && self::validVoteCount($d)
            && self::validEloScore($d);
    }

    public static function validKeyword(string $k): bool
    {
        $k = mb_strtolower(mb_trim($k));
        return preg_match('#' . self::VALID_HOSTNAME_PATTERN . '#i', $k) === 1;
    }

    protected static function validHostname(Domain $d): bool
    {
        return self::validKeyword($d->prefix . $d->keyword . $d->suffix);
    }

    protected static function validTld(Domain $d): bool
    {
        return preg_match('#' . self::VALID_TLD_PATTERN . '#i', $d->tld) === 1;
    }

    protected static function validVoteCount(Domain $d): bool
    {
        return $d->vote_count >= 0;
    }

    protected static function validEloScore(Domain $d): bool
    {
        return $d->elo_score >= 0 && $d->elo_score <= 10000;
    }

    public static function sanitizeKeyword(string $k): string
    {
        $patterns = [ // Order matters!
            '/[^a-z0-9-]/',
            '/(?:' . implode('|', array_filter(Config::read('TLDS'))) . ')+$/', // Strip TLDs before suffixes.
            '/(?:' . implode('|', array_filter(Config::read('SUFFIXES'))) . ')+$/',
            '/^(?:' . implode('|', array_filter(Config::read('PREFIXES'))) . ')+/',
        ];
        foreach ($patterns as $p) {
            /** @var string $k */
            $k = preg_replace(
                $p,
                '',
                mb_strtolower(mb_substr(mb_trim($k), 0, 63)),
            );
        }

        return $k ?? '';
    }
}

/**
 * Simple SQLite3 database wrapper.
 */
class DB
{
    /**
     * ONLY the initial schema lives here. ALL changes must go in
     * MIGRATIONS to facilitate in-place upgrades.
     */
    const array INITIAL_TABLES = [
        <<<EOT
            CREATE TABLE IF NOT EXISTS keywords (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                keyword TEXT NOT NULL DEFAULT '' UNIQUE
                -- description (in addColumn below)
                -- enabled (in addColumn below)
            );
        EOT,
        <<<EOT
            CREATE TABLE IF NOT EXISTS votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prefix TEXT NOT NULL DEFAULT '',
                keyword TEXT NOT NULL,
                suffix TEXT NOT NULL DEFAULT '',
                tld TEXT NOT NULL DEFAULT 'com',
                vote_count INTEGER NOT NULL DEFAULT 0,
                elo_score INTEGER NOT NULL DEFAULT 1000,
                -- available (in addColumn below)
                -- year1_price (in addColumn below)
                -- renewal_price (in addColumn below)
                -- enabled (in addColumn below)
                UNIQUE(prefix, keyword, suffix, tld)
            );
        EOT,
    ];

    /**
     * Pairs of boolean checks and migration commmands.
     *
     * Migrations run top to bottom and MUST be idempotent.
     *
     * @var array<array{0:string, 1:string}> MIGRATIONS
     */
    const array MIGRATIONS = [
        // [
        //     "SELECT NOT EXISTS (SELECT null FROM pragma_table_info('your_table_here') WHERE name = 'your_col_here');",
        //     "ALTER TABLE your_table_here ADD COLUMN your_col_here BOOL NOT NULL DEFAULT FALSE;"
        // ],
    ];

    private SQLite3 $db;

    public function __construct(string $path)
    {
        $this->db = new SQLite3($path);
        $this->init();
        $this->migrations();
        // Make sure the .sqlite3 file at $path gets written out at the end of the http request.
        register_shutdown_function([$this, 'shutdown']);
    }

    private function init(): void
    {
        foreach(self::INITIAL_TABLES as $table) {
            $this->db->exec($table);
        }
    }

    private function migrations(): void
    {
        $this->addColumn('votes', 'available', 'BOOLEAN', true, 'NULL');
        $this->addColumn('votes', 'year1_price', 'REAL', true, 'NULL');
        $this->addColumn('votes', 'renewal_price', 'REAL', true, 'NULL');
        $this->addColumn('keywords', 'description', 'TEXT', false, "''");
        $this->addColumn('keywords', 'enabled', 'BOOLEAN', true, true);
        $this->addColumn('votes', 'enabled', 'BOOLEAN', true, true);
        // TODO: Add votes.availability_last_checked_at TIMESTAMP DEFAULT NULL

        foreach(self::MIGRATIONS as [$check, $migration]) {
            if ($this->db->querySingle($check)) {
                $this->db->exec($migration);
            }
        }
    }

    public function shutdown(): void
    {
        $this->db->close();
    }

    // == Keywords ==========
    /**
     * Get a collection of keywords and descriptions compatible with
     * Helper::select().
     *
     * @return array<string,array{label:string,description:string}>
     */
    public function getKeywords(): array
    {
        $res = $this->db->query('
            SELECT keyword, description
            FROM keywords
            WHERE enabled IS TRUE
            ORDER BY keyword ASC;
        ');
        $reducer = function ($carry, $row) {
            $carry[$row['keyword']] = [
                'label' => $row['keyword'],
                'description' => $row['description'],
            ];
            return $carry;
        };

        $coll = $this->resultCollection($res);
        return array_reduce($coll, $reducer, []);
    }

    public function addKeyword(string $kw, string $desc = ''): bool
    {
        $stmt = $this->prepare('
            INSERT OR IGNORE INTO keywords(keyword, description)
            VALUES (:k, :d);
        ');
        $stmt->bindValue(':k', mb_trim($kw), SQLITE3_TEXT);
        $stmt->bindValue(':d', mb_trim(strip_tags($desc)), SQLITE3_TEXT);
        return (bool)$stmt->execute();
    }

    public function suppressKeywordByVoteId(int $voteId): int|false
    {
        $pre = $this->prepare('
            SELECT id
            FROM keywords
            WHERE keyword = (SELECT keyword FROM votes WHERE id = :i LIMIT 1);
        ');
        $pre->bindValue(':i', $voteId, SQLITE3_INTEGER);
        if (!($res = $pre->execute())
            || !($ary = $res->fetchArray(SQLITE3_ASSOC))
            || !($keywordId = $ary['id'] ?: false)
        ) {
            return false;
        }

        $stmt = $this->prepare('
            UPDATE keywords SET enabled = FALSE WHERE id = :i;
        ');
        $stmt->bindValue(':i', (int)$keywordId, SQLITE3_INTEGER);

        return $stmt->execute() ? $keywordId : false;
    }

    // == Votes ==========
    public function getVoteById(int $id): ?Domain
    {
        $stmt = $this->prepare('
            SELECT * FROM votes WHERE id = :id LIMIT 1;
        ');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $res = $stmt->execute();
        return $this->voteResToDomain($res);
    }

    /**
     * Utilize the `elo_score` column to influence random Domain returned.
     *
     * @param array<int> $excludeIds
     * @return Domain|null
     */
    public function getVoteBiasedRandom(array $excludeIds = []): ?Domain
    {
        $where = '';
        if (count($excludeIds)) {
            $qs = implode(', ', array_fill(0, count($excludeIds), '?'));
            $where = <<<EOW
                AND id NOT IN ({$qs})
            EOW;
        }

        // Bias toward higher ELO using weighted randomness
        $stmt = $this->prepare("
            SELECT *, (abs(random()) / 9223372036854775807.0) * elo_score AS score
            FROM votes
            WHERE available IS NOT FALSE
                AND enabled IS TRUE
            {$where}
            ORDER BY score DESC
            LIMIT 1
            ;
        ");

        if ($where) {
            foreach ($excludeIds as $i) {
                $stmt->bindValue('?', (int)$i, SQLITE3_INTEGER);
            }
        }
        $res = $stmt->execute();

        return $this->voteResToDomain($res);
    }

    /**
     * Return a subset of votes records to select weighted random entries.
     *
     * @return list<Domain>
     */
    public function voteCandidatePool(): array
    {
        $stmt = $this->prepare("
            SELECT *
            FROM votes
            WHERE id >= CAST(random() * (SELECT max(id) FROM votes) AS INTEGER)
                AND available IS NOT FALSE
                AND enabled IS TRUE
                AND keyword NOT IN (SELECT keyword FROM keywords WHERE enabled IS FALSE)
            LIMIT 500
            ;
        ");
        $res = $stmt->execute();

        return $this->resultCollection($res, fn($v) => Domain::fromPost($v));
    }

    public function getVote(
        string $prefix,
        string $keyword,
        string $suffix,
        string $tld,
    ): ?Domain
    {
        $stmt = $this->prepare('
            SELECT *
            FROM votes
            WHERE prefix = :p
                AND keyword = :k
                AND suffix = :s
                AND tld = :t
            ;
        ');
        $stmt->bindValue(':p', $prefix, SQLITE3_TEXT);
        $stmt->bindValue(':k', $keyword, SQLITE3_TEXT);
        $stmt->bindValue(':s', $suffix, SQLITE3_TEXT);
        $stmt->bindValue(':t', $tld, SQLITE3_TEXT);
        $res = $stmt->execute();

        return $this->voteResToDomain($res);
    }

    public function getVoteByDomain(Domain $d): ?Domain
    {
        return $this->getVote(
            $d->prefix,
            $d->keyword,
            $d->suffix,
            $d->tld,
        );
    }

    public function getVoteByDomainName(string $d): ?Domain
    {
        $stmt = $this->prepare(<<<EOSQL
            SELECT *, (prefix || keyword || suffix || '.' || tld) AS name
            FROM votes
            WHERE name = :n
            LIMIT 1
            ;
        EOSQL);
        $stmt->bindValue(':n', $d, SQLITE3_TEXT);
        $res = $stmt->execute();

        return $this->voteResToDomain($res);
    }

    public function voteCount(): int
    {
        return $this->count('votes');
    }

    public function voteCountSum(): int
    {
        return (int)$this->db->querySingle('SELECT SUM(vote_count) FROM votes;');
    }

    public function addDomain(Domain $d): ?Domain
    {
        return $this->addVote(
            $d->prefix,
            $d->keyword,
            $d->suffix,
            $d->tld,
            $d->vote_count,
            $d->elo_score,
            $d->available,
            $d->year1_price,
            $d->renewal_price,
            $d->enabled,
        );
    }

    public function addVote(
        string $prefix,
        string $keyword,
        string $suffix,
        string $tld,
        int $voteCount = 0,
        int $eloScore = 1000,
        ?bool $available = null,
        ?float $year1Price = null,
        ?float $renewalPrice = null,
        bool $enabled = true,
    ): ?Domain
    {
        $stmt = $this->prepare(<<<EOS
            INSERT INTO votes(prefix, keyword, suffix, tld, vote_count,
                elo_score, available, year1_price, renewal_price, enabled)
            VALUES(:p, :k, :s, :t, :v, :e, :a, :y, :r, :n)
            ON CONFLICT(prefix, keyword, suffix, tld) DO
            NOTHING  -- A combination coming up as a candidate, by chance, repeatedly, doesn't constitute a "vote" for it.
            -- UPDATE SET vote_count = vote_count + 1
            ;
        EOS);
        $stmt->bindValue(':p', $prefix, SQLITE3_TEXT);
        $stmt->bindValue(':k', $keyword, SQLITE3_TEXT);
        $stmt->bindValue(':s', $suffix, SQLITE3_TEXT);
        $stmt->bindValue(':t', $tld, SQLITE3_TEXT);
        $stmt->bindValue(':v', $voteCount, SQLITE3_INTEGER);
        $stmt->bindValue(':e', $eloScore, SQLITE3_INTEGER);
        $stmt->bindValue(':a', $available, SQLITE3_INTEGER); // no built-in BOOL type
        $stmt->bindValue(':y', $year1Price, SQLITE3_FLOAT);
        $stmt->bindValue(':r', $renewalPrice, SQLITE3_FLOAT);
        $stmt->bindValue(':n', $enabled, SQLITE3_INTEGER);
        $res = $stmt->execute();

        return $this->getVote($prefix, $keyword, $suffix, $tld);
    }

    public function updateVote(Domain $d): void
    {
        $stmt = $this->prepare('
            UPDATE votes SET
                prefix = :p,
                keyword = :k,
                suffix = :s,
                tld = :t,
                vote_count = :v,
                elo_score = :e,
                available = :a,
                year1_price = :y,
                renewal_price = :r,
                enabled = :n
            WHERE id = :id;
        ');
        $stmt->bindValue(':id', $d->id, SQLITE3_INTEGER);
        $stmt->bindValue(':p', $d->prefix, SQLITE3_TEXT);
        $stmt->bindValue(':k', $d->keyword, SQLITE3_TEXT);
        $stmt->bindValue(':s', $d->suffix, SQLITE3_TEXT);
        $stmt->bindValue(':t', $d->tld, SQLITE3_TEXT);
        $stmt->bindValue(':v', $d->vote_count, SQLITE3_INTEGER);
        $stmt->bindValue(':e', $d->elo_score, SQLITE3_INTEGER);
        $stmt->bindValue(':a', $d->available, SQLITE3_INTEGER);
        $stmt->bindValue(':y', $d->year1_price, SQLITE3_FLOAT);
        $stmt->bindValue(':r', $d->renewal_price, SQLITE3_FLOAT);
        $stmt->bindValue(':n', $d->enabled, SQLITE3_INTEGER);

        $stmt->execute()->finalize();
    }

    // == Leaderboard ==========
    /**
     * Fetch the top rated (or top voted) Domains.
     *
     * @param string $order
     * @return list<Domain>
     */
    public function getLeaderboard(string $order): array
    {
        $sort = (in_array($order, ['elo_score', 'vote_count']) ? $order : 'elo_score');
        $stmt = $this->prepare("
            SELECT *
            FROM votes
            WHERE vote_count > 0
                AND available IS NOT FALSE
                AND enabled IS TRUE
            ORDER BY {$sort} DESC, (prefix || keyword || suffix || tld) ASC
            LIMIT :l
            ;
        ");
        $stmt->bindValue(':l', Config::read('LEADERBOARD_LIMIT'), SQLITE3_INTEGER);
        $res = $stmt->execute();

        return $this->resultCollection($res, fn($v) => Domain::fromPost($v));
    }

    // == Domains ==========
    /**
     * Fetch Domains that have `available IS NULL`.
     *
     * @return list<Domain>
     */
    public function domainsNeedingAvailability(): array
    {
        $stmt = $this->prepare('
            SELECT *
            FROM votes
            WHERE enabled IS TRUE
                AND (
                    available IS NULL
                    OR year1_price IS NULL
                    OR renewal_price IS NULL
                )
            ORDER BY (prefix || keyword || suffix || tld) ASC
            LIMIT 100
            ;
        ');
        $res = $stmt->execute();

        return $this->resultCollection($res, fn($v) => Domain::fromPost($v));
    }


    public function vetoDomain(int $id): bool
    {
        $stmt = $this->prepare('
            UPDATE votes SET enabled = FALSE WHERE id = :i;
        ');
        $stmt->bindValue(':i', $id);

        return (bool)$stmt->execute();
    }

    // == Helpers ==========
    public function count(string $table, string $conditions = ''): int
    {
        $where = '';
        if (strlen($conditions) > 0) {
            $where = "WHERE {$conditions}";
        }

        return (int)$this->db->querySingle(
            "SELECT COUNT(*) AS count FROM {$table} {$where};",
        );
    }

    private function addColumn(
        string $table,
        string $name,
        string $type,
        bool $null = false,
        mixed $default = null,
    ): mixed
    {
        if ($this->columnExists($table, $name)) {
            return true;  // no op
        }

        $null = ($null ? '' : 'NOT NULL');
        $default = ($default ? "DEFAULT {$default}" : '');
        $q = "ALTER TABLE {$table} ADD COLUMN {$name} {$type} {$null} {$default};";
        return $this->db->exec($q);
    }

    private function columnExists(string $table, string $name): bool
    {
        $q = "SELECT EXISTS (SELECT null FROM pragma_table_info('{$table}') WHERE name = '{$name}');";
        return (bool)$this->db->querySingle($q);
    }

    protected function voteResToDomain(SQLite3Result|false $res): ?Domain
    {
        if ($res && ($ary = $res->fetchArray(SQLITE3_ASSOC))) {
            return Domain::fromPost($ary);
        }

        return null;
    }

    /**
     * Wrapper around SQLite3::prepare() to handle standard error checking.
     *
     * @param string $sql
     * @return SQLite3Stmt
     * @throws RuntimeException When the call to prepare() fails.
     */
    private function prepare(string $sql): SQLite3Stmt
    {
        $stmt = $this->db->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Prepare failed: ' . $this->db->lastErrorMsg());
        }

        return $stmt;
    }

    /**
     * Helper to convert SQLlite3Results into arrays.
     *
     * Applies a transformation closure to each value in the process to
     * save repeated loops.
     *
     * @param SQLite3Result|false $res
     * @param callable|null $transformer
     * @return list<mixed>
     */
    protected function resultCollection(
        SQLite3Result|false $res,
        ?callable $transformer = null,
    ): array
    {
        if ($res === false) {
            return [];
        }

        $transformer ??= fn($r) => $r; // Identity function is default.
        $out = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $transformer($row);
        }
        return $out;
    }
}

class BaseJsonApi
{
    const API_BASE = '';
    const API_TIMEOUT_SECS = 60;
    const HEADERS_BASE = [
        'Content-Type: application/json',
    ];

    public function __construct(
        protected string $apiKey = '',
    ) {}

    // == Internal helpers.

    /**
     * Lowest level HTTP request processor.
     *
     * @param string $url Fully qualified target URL with all necessary
     *   query params.
     * @param string $body String POST body contents.
     * @param string $method Either 'GET' or 'POST'.
     * @param list<string> $headers All additional headers to send with
     *   the request.
     * @param array<mixed> $curlOpts Curl setup overrides.
     * @return mixed The result of ::requestHandler() having processed the
     *   raw response string.
     * @throws RuntimeException When curl_exec() fails.
     * @throws RuntimeException When a `429` http responsse code is encountered.
     */
    protected function curl(
        string $url,
        string $body = '',
        string $method = 'GET',
        array $headers = [],
        array $curlOpts = [],
    ): mixed
    {
        $ch = curl_init();
        // Only backfill any option not already set.
        curl_setopt_array($ch, $curlOpts + [
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $body, // POST payload
            CURLOPT_POST => ($method === 'POST'),
            CURLOPT_HTTPHEADER => $this->headers($headers), // Send these request headers
            CURLOPT_TIMEOUT => $this::API_TIMEOUT_SECS,
            CURLINFO_HEADER_OUT => true, // Capture response headers
            CURLOPT_RETURNTRANSFER => true, // Return the response body as a string
        ]);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        if (curl_error($ch)) {
            throw new RuntimeException('Curl error: ' . curl_error($ch));
        }
        // curl_close($ch); // Not required from php v8.0 on.

        // Handle curl-level errors here, leave payload-level errors to
        // responseHandler.
        switch($info['http_code'] ?? 200) {
            case 429:
                throw new RuntimeException("
                    Too fast (429). Cooldown: {$info['request_header']}
                ");
            default:
                break;
        }

        /** @var string $response */
        return $this->responseHandler($response);
    }

    /**
     * Join the API base URL with the provided path and any query params.
     *
     * @param string $path Only the URL fragment following API_BASE.
     * @param array<string,string> $params Query params to encode into the URL.
     * @return string The fully qualified URL.
     */
    protected function url(string $path, array $params = []): string
    {
        return $this::API_BASE . $path . '?' . http_build_query($params + [
            //'apiKey' => $this->apiKey,
        ]);
    }

    /**
     * Collect default, auth, and custom header definitions.
     *
     * @param array<string> $custom
     * @return array<string>
     */
    protected function headers(array $custom = []): array
    {
        $auth = empty($this->apiKey) ? [] : [
            // "Bearer {$this->apiKey}",
        ];

        return array_merge($this::HEADERS_BASE, $custom, $auth);
    }

    /**
     * API-wide payload encoding.
     *
     * @param array<mixed> $payload Whatever raw input is required for the request.
     * @return string Must always be a string body payload for POST requests.
     */
    protected function payload(array $payload): string
    {
        return json_encode($payload) ?: ''; // This api accepts JSON-encoded strings.
    }

    /**
     * Handle API payload level errors.
     *
     * @param string $res The raw curl response body string.
     * @return StdClass The generic processed result. Endpoint wrappers
     *   should implement further refinement.
     */
    protected function responseHandler(string $res): StdClass
    {
        $res = json_decode($res, false, 4);

        switch($res->status ?? 200) {
            // case 429:
            //     throw new RateLimitException("
            //         Too fast (429). Cooldown: {$res->headers()}
            //     ");
            //     break;
            default:
                break;
        }

        return $res;
    }

    /**
     * Process API results down into an array using a reducer function.
     *
     * @param array<mixed>|StdClass $res Either a single result object, or a collection of them.
     * @param callable|null $reducer A function to use to process each $res.
     * @param mixed $carry The default collector for results. Single objects assume `null`. Arrays should pass `[]`.
     * @return mixed
     */
    protected function reduce(
        array|StdClass $res,
        ?callable $reducer = null,
        mixed $carry = null,
    ): mixed
    {
        if (is_null($reducer)) {
            return $res;
        }

        if (!is_iterable($res)) {
            return $reducer($res);
        }

        return array_reduce($res, $reducer, $carry);
    }
}

/**
 * API interface for checking domain name pricing.
 */
class PricingApi extends BaseJsonApi
{
    # Ref: https://porkbun.com/api/json/v3/documentation#tag/pricing/POST/pricing/get
    const API_BASE = 'https://api.porkbun.com/api/json/v3/';

    public function __construct(
        protected string $apiKey = '',
        protected string $secretApiKey = '',
    ) {}

    /**
     * Fetch generic pricing for provided top level domains.
     *
     * @param list<string> $tlds
     * @return array<string,array{year1_price:float,renewal_price:float}> List of
     *   domain => [ year1_price => float, renewal_price => float ] entries.
     */
    public function getTldPricing(array $tlds): array
    {
        $res = $this->curl(
            $this->url('pricing/get'),
            $this->payload(['tlds' => array_unique(array_filter($tlds))]),
            'POST',
        );

        return $this->reduce(
            array_keys(get_object_vars($res->pricing)),
            function ($c, $tld) use ($res) {
                $c[$tld] = [
                    'year1_price' => (float)$res->$tld->registration,
                    'renewal_price' => (float)$res->$tld->renewal,
                ];
                return $c;
            },
            [],
        );
    }

    /**
     * Fetch domain availability and pricing.
     *
     * @see https://porkbun.com/api/json/v3/documentation#tag/domain/POST/domain/checkDomain/{domain}
     * @param string $d
     * @return array{available:bool,year1_price:float,renewal_price:float}
     */
    public function getDomainPricing(string $d): array
    {
        $res = $this->curl(
            $this->url('domain/checkDomain/' . urlencode($d)),
            '',
            'POST'
        );

        return [
            'available' => (bool)($res->response->avail === 'yes'),
            'year1_price' => (float)$res->response->price,
            'renewal_price' => (float)$res->response->regularPrice,
            'ttlRemaining' => ($res->ttlRemaining ? ((int)$res->ttlRemaining) : 10),
        ];
    }

    protected function headers(array $custom = []): array
    {
        $auth = [];
        if (!empty($this->apiKey)) { $auth[] = "X-API-Key: {$this->apiKey}"; }
        if (!empty($this->secretApiKey)) { $auth[] = "X-Secret-API-Key: {$this->secretApiKey}"; }

        return parent::headers($custom + $auth);
    }

    /**
     * Common response processing.
     *
     * @param string $res The raw HTTP response body string.
     * @return StdClass In this subclass, a json_decode()d object.
     * @throws RuntimeException When json_decode() fails.
     * @throws RuntimeException When the response payload indicates an error state.
     */
    protected function responseHandler(string $res): StdClass
    {
        $body = json_decode(mb_trim($res, " '"), false, 6);
        switch($body?->status) {
            case null:
                throw new RuntimeException(
                    'JSON decode failed: ' . json_last_error_msg()
                );
            case 'ERROR':
                throw new RuntimeException(
                    "Request failed. {$body->code}: {$body->message}"
                );
            default:
                break;
        }

        return $body;
    }
}

/**
 * Generate new and fetch pre-existing combinations of:
 *   prefix + keyword + suffix + . + tld
 * Utilizes ELO matching-making and ranking updates.
 */
class DomainGen
{
    /**
     * Avoid multiple DB fetches within a single request.
     *
     * @var list<string>
     */
    protected array $keywordsCache;

    public function __construct(private DB $db) {}

    /**
     * Return a pair of Domain objects from the DB.
     *
     * Will either be pre-existing, or freshly generated and INSERTed.
     *
     * @return array{0:Domain|null,1:Domain|null}
     */
    public function pickPair(): array
    {
        // Select first candidate.
        $a = $this->pickCandidate();
        $excludes = $a?->id ? [$a->id] : [];

        // Select second candidate.
        if ($this->probability(Config::read('PAIRWISE_PROB'))) {
            $b = $this->pickCandidate($excludes);
        } else {
            $b = $this->generateCandidate();
        }

        return [$a, $b];
    }

    /**
     * Either pick a DB vote entry, or generate a weighted random selection.
     *
     * @param array<int> $excludeIds
     * @return Domain|null
     */
    protected function pickCandidate(array $excludeIds = []): ?Domain
    {
        if (
            $this->probability(Config::read('BIAS_EXISTING_PROB'))
            && $this->db->voteCount() > count($excludeIds)
            //&& ($vote = $this->db->getVoteBiasedRandom(array_filter($excludeIds)))
            && ($vote = $this->pickWeightedCandidate(array_filter($excludeIds)))
        ) {
            return $vote;
        }

        // Fall back to generated (or null, when no keywords are available.)
        return $this->generateCandidate();
    }

    /**
     * Incorporate all availble weighting signals to pick a DB votes entry.
     *
     * @param array<int> $excludeIds
     * @return Domain|null
     */
    public function pickWeightedCandidate(array $excludeIds = []): ?Domain
    {
        $best = null;
        $bestKey = -INF;

        foreach ($this->db->voteCandidatePool() as $vote) {
            $w = $this->rowWeight($vote);

            if ($w <= 0)
            {
                continue;
            }

            $u = mt_rand() / mt_getrandmax();
            $key = pow($u, 1 / $w);

            if ($key > $bestKey && !in_array($vote->id, $excludeIds)) {
                $bestKey = $key;
                $best = $vote;
            }
        }

        return $best;
    }

    protected function generateCandidate(): ?Domain
    {
        $MAX_TRIES = 3;
        $c = 0;
        do {
            $t = $this->randomDomain();
        } while ($c++ < $MAX_TRIES
            && $t
            && $this->db->getVote($t['prefix'], $t['keyword'], $t['suffix'], $t['tld'])
        );

        if (empty($t)) {
            return null;
        }

        return $this->db->addVote(
            $t['prefix'],
            $t['keyword'],
            $t['suffix'],
            $t['tld'],
        );
    }

    /**
     * Generate a random combo of (prefix + keyword + suffix + tld).
     *
     * @return array{
     *   prefix:string,
     *   keyword:string,
     *   suffix:string,
     *   tld:string
     * }|null
     */
    protected function randomDomain(): ?array
    {
        $this->keywordsCache ??= array_keys($this->db->getKeywords());
        if (!$this->keywordsCache) return null;

        return [
            'prefix' => $this->arrayWeightedRand(Config::read('PREFIXES')),
            'keyword' => $this->keywordsCache[array_rand($this->keywordsCache)],
            'suffix' => $this->arrayWeightedRand(Config::read('SUFFIXES')),
            'tld' => $this->arrayWeightedRand(Config::read('TLDS')),
        ];
    }

    /**
     * Returns a weighted random element from a numerically indexed array.
     *
     * Earlier elements are more likely to be chosen. Weights are
     * based on the geometric distribution of each item's index and the
     * configured ARRAY_WEIGHT_FACTOR, where smaller weight values produces
     * more drastic favoring of early elements.
     *
     * @param list<mixed> $items A numerically indexed array, sorted with the
     *   "most preferable" entry at the top, in descending order of preference.
     * @return mixed The element chosen from the array.
     * @throws InvalidArgumentException When array is empty.
     */
    protected function arrayWeightedRand(array $items): mixed
    {
        $n = count($items);
        if ($n === 0) {
            throw new InvalidArgumentException('Array must not be empty.');
        }

        $f = Config::read('ARRAY_WEIGHT_FACTOR');
        $r = mt_rand() / mt_getrandmax();

        if ($f == 1.0) {
            $index = $r * $n;
        } else {
            $index = log(1 - $r * (1 - pow($f, $n))) / log($f);
        }

        return $items[ min((int)floor($index), $n - 1) ];
    }

    protected function rowWeight(Domain $d): float
    {
        // Avoid runaway growth on votes (log dampening).
        $votes = pow(1 + $d->vote_count, $this->factor('VOTE_WEIGHT_FACTOR'));

        // Normalize ELO.
        $elo = exp($this->factor('ELO_WEIGHT_FACTOR') * $d->elo_score);

        // Bias toward smaller prices.
        $year1Price = exp(
            -$this->factor('YEAR1_WEIGHT_FACTOR') * abs($d->year1_price ?? 100)
        );
        $renewalPrice = exp(
            -$this->factor('RENEWAL_WEIGHT_FACTOR') * abs($d->renewal_price ?? 100)
        );

        return $votes * $elo * $year1Price * $renewalPrice;
    }

    /**
     * Adjust the provided ELO scores for the winner and loser.
     *
     * Returns a pair of updated values. Example usage:
     *
     *    [$newWinnerElo, $newLoserElo] = DomainGen::updateElos($oldWinnerElo, $oldLoserElo);
     *
     * @param integer $winnerElo
     * @param integer $loserElo
     * @return int[] A pair of updated [winnerElo, loserElo] values.
     */
    public static function updateElos(int $winnerElo, int $loserElo): array
    {
        $k = 32; // ELO magic value.
        $expected = 1 / (1 + pow(10, ($loserElo - $winnerElo) / 400));
        $winnerNew = (int)($winnerElo + $k * (1 - $expected));
        $loserNew  = (int)($loserElo + $k * (0 - (1 - $expected)));

        return [$winnerNew, $loserNew];
    }

    protected function probability(float $percent): bool
    {
        return (mt_rand() / mt_getrandmax()) < $percent;
    }

    protected function factor(string $key): float
    {
        return 1.0 / (Config::read($key) ?? 1.0);
    }
}

trait Redirector
{
    protected function redirToAction(string $action): void
    {
        $this->redirect(Helper::navUrl($action));
    }

    protected function redirect(string $location): void
    {
        header("Location: {$location}");
        exit;
    }
}

/**
 * Page content renderers. Access through `->display($page)`.
 *
 * Also the keeper of centralized DB and DomainGen instances.
 */
class Pages
{
    use Redirector;

    protected DB $db;
    protected DomainGen $gen;

    public function __construct()
    {
        $this->db = new DB(Config::read('DB_FILE'));
        $this->gen = new DomainGen($this->db);
    }

    /**
     * Route the requested action to the function that handles it.
     *
     * @param string $page The name of the protected method (page) to execute.
     * @param array<mixed> $args Any available $_GET data.
     * @return string The rendered html for the page.
     */
    public function dispatch(string $page, array $args = []): string
    {
        if (!in_array($page, get_class_methods($this))) {
            Flash::add("Requested page not found: {$page}", 'danger');
            return <<<EO404
                <h2></h2>
                <p>The requested page was not found.</p>
            EO404;
        }

        return $this->$page($args);
    }

    /**
     * Present a form for choosing one of two domain options.
     *
     * @param array<mixed> $args Not used.
     * @return string
     */
    protected function vote(array $args): string
    {
        $superMode = isset($args['super']);
        [$a, $b] = $this->gen->pickPair();

        if (!$a || !$b) {
            return <<<EOHTML
                <h2>⚠️ Add Keywords First</h2>
                <p>In order to vote on domain suggestions and randomly-generated combinations, there must be at least one {$this->textLink('add_keyword', 'keyword')} in the database.</p>
            EOHTML;
        }

        $hiddenSuper = ($superMode ? '<input type="hidden" name="super" value="yes">' : '');

        $superButtons = function(Domain $d, bool $show): string {
            if (!$show) {
                return '';
            }
            $classes = 'btn btn-sm z-3';

            return <<<EOB
                <div class="position-absolute top-0 end-0 btn-group" role="group" aria-label="Super functions">
                    <button type="submit" name="veto_domain" value="{$d->id}" title="Veto this domain! ('{$d}' won't show up in voting or on the leaderboard anymore.)" class="{$classes} btn-warning">⛔️</button>
                    <button type="submit" name="suppress_keyword" value="{$d->id}" title="Suppress this keyword! ('{$d->keyword}' won't be available to use when suggesting new domains anymore.)" class="{$classes} btn-danger">🆇</button>
                </div>
            EOB;
        };

        $voteButton = function (
            Domain $d,
            string $pos,
            bool $show,
        ) use ($superButtons): string {
            $prices = '';
            if ($d->year1_price > 0) {
                $prices .= ' ' . Helper::badge("1️⃣ {$d->year1_price}");
            }
            if ($d->renewal_price > 0) {
                $prices .= ' ' . Helper::badge("🔁 {$d->renewal_price}");
            }
            return <<<EOB
                <div class="position-relative flex-grow-1 flex-shrink-0" style="min-width: 49%;">
                    {$superButtons($d, $show)}
                    <input type="hidden" name="{$pos}" value="{$d->id}">
                    <button type="submit" name="winner" value="{$pos}" class="btn btn-block btn-primary fs-1 w-100 py-5 mb-3 shadow bg-gradient">
                        {$d}{$prices}
                    </button>
                </div>
            EOB;
        };

        return <<<EOHTML
            <h2>Vote</h2>
            <p>Click the domain you like <b>more</b> as the production name of this project. If you prefer neither, just {$this->textLink('vote', '🔁 refresh the page')} to cycle the options.</p>
            <form method="post">
                {$hiddenSuper}
                <div class="d-flex flex-wrap align-content-stretch justify-content-between gap-2 w-100">
                    {$voteButton($a, 'a', $superMode)}
                    {$voteButton($b, 'b', $superMode)}
                </div>
            </form>
             <p class="fw-lighter fst-italic">The choices are drawn from the set of {$this->link('add_domain', 'preconfigured prefixes, suffixes and TLDs')}, as well as a {$this->link('add_keyword', 'user-submitted keyword')} and {$this->link('add_domain', 'domain recommendations')}.</p>
       EOHTML;
    }

    /**
     * Present the current leaderboard table.
     *
     * @param array{order?:string} $args
     * @return string
     */
    protected function leaderboard(array $args): string
    {
        $order = $args['order'] ?? 'elo_score';
        $leaders = $this->db->getLeaderboard($order);

        if (count($leaders) > 0) {
            $rows = '';
            foreach ($leaders as $d) {
                $rows .= Helper::tr([
                    Helper::domainWithPriceLink((string)$d),
                    $d->vote_count,
                    $d->elo_score,
                ], $d->available === true ? '' : 'table-warning');
            }
        } else {
            $rows = Helper::tr([
                "(No qualifying domains. Maybe {$this->link('update_availability', 'update availability')}?)",
                '',
                '',
            ], 'table-warning');
        }
        $tabLink = fn(string $title, string $order, string $cur) => Helper::link(
            'leaderboard',
            $title,
            ['nav-link', ($order === $cur ? 'active' : '')],
            ['order' => $order],
        );

        return <<<EOTABLE
            <h2>Leaderboard</h2>
            <p>Displays the highest scoring domain suggestions by <a href="https://en.wikipedia.org/wiki/Elo_rating_system">ELO ranking</a>. Entries in the <code>votes</code> table are created when they are first generated randomly. So this table excludes any entries that have never gotten an explicit human vote. Domains with {$this->textLink('update_availability', 'unknown availability')} for registration are <span class="bg-warning-subtle">marked</span>.</p>
            <table class="table table-responsive-sm table-bordered table-hover caption-top my-3">
                <caption class="pb-0">
                    <ul class="nav nav-tabs">
                        <li class="nav-item">
                            {$tabLink('By Votes', 'vote_count', $order)}
                        </li>
                        <li class="nav-item">
                            {$tabLink('By ELO', 'elo_score', $order)}
                        </li>
                    </ul>
                </caption>
                <thead class="table-light"><tr>
                    <th scope="col">Domain</th>
                    <th scope="col" class="text-end">Votes</th>
                    <th scope="col" class="text-end">ELO</th>
                </tr></thead>
                <tbody class="table-group-divider">
                {$rows}
                </tbody>
            </table>
        EOTABLE;
    }

    /**
     * Form for submitting a new (prefix + keyword + suffix + tld) pair.
     *
     * Meant for users to hand-fill domain suggestions.
     *
     * @param array<mixed> $args Not used.
     * @return string
     */
    protected function add_domain(array $args): string
    {
        $keywords = $this->db->getKeywords();
        if (count($keywords) === 0) {
            Flash::add(
                'No keywords in DB. Please add (at least) one keyword first.',
                'warning',
            );
            $this->redirToAction('add_keyword');
        }

        $prefixSelect = Helper::select('prefix', 'Prefix', Config::read('PREFIXES'));
        $keywordSelect = Helper::select('keyword', 'Keyword', $keywords);
        $suffixSelect = Helper::select('suffix', 'Suffix', Config::read('SUFFIXES'));
        $tldSelect = Helper::select('tld', 'TLD', Config::read('TLDS'), '.');

        return <<<EOHTML
            <h2>Add Domain</h2>
            <p>Use this form to add an explicit combination to the pool. If necessary, you can {$this->textLink('add_keyword', 'add a keyword')} first. Prefixes, suffixes, and TLDs are sorted in descending order of preference. Entries at the top are preferred to those at the bottom.</p>
            <form method="post" class="my-3 p-3 border rounded shadow">
                <div class="row column-gap-0 mb-3">
                    <div class="col-sm-2 pe-0">{$prefixSelect}</div>
                    <div class="col-sm-6 px-0">{$keywordSelect}</div>
                    <div class="col-sm-2 px-0">{$suffixSelect}</div>
                    <div class="col-sm-2 ps-0">{$tldSelect}</div>
                </div>
                <div class="row">
                    <div class="col">
                        <button class="btn btn-primary">Add Domain</button>
                    </div>
                </div>
            </form>
        EOHTML;
    }

    /**
     * Present a form for submitting a single keyword and description pair.
     *
     * Meant for users to hand-fill keyword suggestions with an
     * accompanying explanation of the keyword.
     *
     * @param array<mixed> $args Not used.
     * @return string
     */
    protected function add_keyword(array $args): string
    {
        $prefixes = join(', ', array_filter(Config::read('PREFIXES')));
        $suffixes = join(', ', array_filter(Config::read('SUFFIXES')));
        $addDomainUrl = Helper::navUrl('add_domain');

        return <<<EOHTML
            <h2>Add Keyword</h2>
            <p>Keywords are the &quot;core&quot; part of the domain name, without any prefixes to help with domain availability, and without a <abbr title="top level domain">TLD</abbr> chosen. Once you add a keyword, you can add a specific combination of <code>prefix + keyword + suffix . tld</code> as {$this->textLink('add_domain', 'a voting suggestion')}.</p>
            <form method="post" class="row g-3 align-items-top my-3 p-3 border rounded shadow">
                <div class="col-4">
                    <label for="keyword" class="form-label">New Keyword</label>
                    <input name="keyword" type="text" placeholder="gavel" class="form-control">
                    <div class="form-text">Configured prefixes (<code>{$prefixes}</code>) and suffixes (<code>{$suffixes}</code>) will be stripped.</div>
                </div>
                <div class="col-8">
                    <label for="description" class="form-label">Reasoning, justification, inspiration, explanation, etc.</label>
                    <input name="description" type="text" placeholder="Burglin lists fine art lots, for which there will be a gavel hammered." class="form-control">
                    <div class="form-text">This description will be shown when users <a href="{$addDomainUrl}">add a suggested domain</a>.</div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary">Add Keyword</button>
                </div>
            </form>
            <p>Looking to {$this->textLink('bulk_keywords', 'add a bunch of keywords')} all at once?</p>
        EOHTML;
    }

    /**
     * Present a form for submitting multiple keyword and description pairs.
     *
     * Intended to be used with copied data from Excel or Google Sheets.
     *
     * @param array<mixed> $args Not used.
     * @return string
     */
    protected function bulk_keywords(array $args): string
    {
        $disallowedChars = '/' . '[^a-z0-9-]' . '/g';
        $tlds = join(', ', array_filter(Config::read('TLDS')));
        $prefixes = join(', ', array_filter(Config::read('PREFIXES')));
        $suffixes = join(', ', array_filter(Config::read('SUFFIXES')));

        return <<<EOHTML
            <h2>Bulk Add Keywords</h2>
            <p>Accepts <i>unformatted</i> keywords (like in the <a href="https://docs.google.com/spreadsheets/d/1SrR-vzIzrgiJr9fiEwYEbkvauQ1zSh-8o_yQsHOEUrs/edit?gid=0#gid=0">google sheet</a>). Optionally accept a second column (tab-separated) with a description or explanation of the keyword's inclusion or utility.</p>

            <form method="post" class="row g-3 align-items-top my-3 p-3 border rounded shadow">
                <div class="col-12">
                    <label for="tsv" class="form-label">Bulk Keywords, Descriptions</label>
                    <textarea
                        aria-label="Enter one keyword per line. Each line can contain an optional tab, followed by a description or explanation for why that keyword is being recommended."
                        name="tsv"
                        class="form-control"
                        rows="10"
                        style="resize: vertical;"
                        placeholder="Unformatted keyword here! \\t This keyword represents our deep connection to words."
                    ></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" name="search" value="search" class="btn btn-primary">Submit Keywords</button>
                </div>
            </form>

            <p>Processing steps performed:</p>
            <ol>
                <li>Split input on newline (<code>\\n</code>) to get individual entries.</li>
                <li>Split each row on tab (<code>\\t</code>) to get keyword, description pairs.</li>
                <li>For the keyword:
                    <ol>
                        <li>Trim to 63 total characters.</li>
                        <li>Lowercase the string.</li>
                        <li>Remove characters that are not URL-safe. (<code>{$disallowedChars}</code>)</li>
                        <li>Strip any set of configured TLDs from the end of the string. (<code>{$tlds}</code>)</li>
                        <li>Strip configured suffixes from the end of the string. (<code>{$suffixes}</code>)</li>
                        <li>Strip configured prefixes from the start. (<code>{$prefixes}</code>)</li>
                    </ol>
                </li>
                <li>Skip INSERTing any keywords already present in the database.</li>
                <li>For the description:
                    <ol>
                        <li>Strip HTML tags.</li>
                    </ol>
                </li>
            </ol>
        EOHTML;
    }

    /**
     * Present a form for reviewing domains lacking availability data.
     *
     * @param array<mixed> $args Not used.
     * @return string
     */
    protected function update_availability(array $args): string
    {
        $domainsLackingAvailability = $this->db->domainsNeedingAvailability();
        $submitDisabled = '';
        if (count($domainsLackingAvailability) > 0) {
            $rows = '';
            foreach ($domainsLackingAvailability as $d) {
                $rows .= Helper::tr([
                    (string)$d,
                    $d->vote_count,
                    $d->elo_score,
                ]);
            }
        } else {
            $rows = Helper::tr(['(No qualifying domains.)', '', '']);
            $submitDisabled = 'disabled';
        }

        return <<<EOHTML
            <h2>Update Domain Availability &amp; Pricing</h2>
            <p>This app's voting and leaderboard suppress domains that have been determined to not be available for registration. Domain availability and pricing is fetched in bulk, only on request from the form below, using the <a href="https://porkbun.com/api/json/v3/documentation#tag/domain/POST/domain/checkDomain/{domain}">Porkbun Domain Availability API</a>.</p>
            <table class="table table-danger table-responsive-sm table-bordered table-striped table-hover caption-top my-3">
                <caption class="pb-0">
                    Domains in the local SQLite database that don't have a known availability or pricing.
                </caption>
                <thead>
                    <tr><th scope="col">Domain Name</td><th scope="col">Votes</td><th scope="col">ELO</td></tr>
                </thead>
                <tbody>
                {$rows}
                </tbody>
            </table>
            <form method="post">
                <p class="border border-warning rounded px-2 py-1 bg-warning-subtle">⚠️ This can take a long time. The Porkbun API <a href="https://porkbun.com/api/json/v3/documentation#description/rate-limiting" class="alert-link">rate limits to one request every 10 seconds</a>.</p>
                <button type="submit" class="btn btn-primary" {$submitDisabled}>Update Domain Availability</button>
            </form>
        EOHTML;
    }

    protected function stats(): string
    {
        $prefixCount = count(Config::read('PREFIXES'));
        $keywordCount = $this->db->count('keywords', 'enabled IS TRUE');
        $suffixCount = count(Config::read('SUFFIXES'));
        $tldCount = count(Config::read('TLDS'));
        $data = [
            'Prefix Count' => $prefixCount,
            'Enabled Keyword Count' => $keywordCount,
            'Suffix Count' => $suffixCount,
            'TLD Count' => $tldCount,
            'Total Possible Permutations' => $prefixCount * $keywordCount * $suffixCount * $tldCount,
            '--' => '--',
            'Enabled Suggested Domains Count' => $this->db->count('votes', 'enabled IS TRUE'),
            'Available Suggested Domains Count' => $this->db->count('votes', 'enabled IS TRUE AND available IS TRUE'),
            'Total Votes Cast' => $this->db->voteCountSum(),
            // TODO: More options. Most expensive domain, least expensive domain, ???
        ];

        $rows = '';
        foreach ($data as $label => $value) {
            $rows .= Helper::tr([$label, $value]);
        }

        return <<<EOHTML
            <h2>Assorted Statistics</h2>
            <table class="table table-responsive-sm table-bordered table-striped table-hover caption-top my-3">
                <thead>
                    <tr><th scope="col">Stat</td><th scope="col">Value</td></tr>
                </thead>
                <tbody>
                    {$rows}
                </tbody>
            </table>
        EOHTML;
    }

    protected function errors(): string
    {
        $formatter = function ($err) {
            $trace = Helper::h($err['trace']);
            return <<<EOE
                <h3 class="text-danger">{$err['msg']}</h3>
                <pre class="border border-danger bg-danger-subtle"><code>{$trace}</code></pre>
            EOE;
        };

        return implode('', array_map($formatter, Flash::errors()));
    }

    protected function link(string $action, string $title): string
    {
        return Helper::link($action, $title);
    }

    protected function textLink(string $action, string $title): string
    {
        return Helper::textLink($action, $title);
    }
}

/**
 * Like the Pages class, handle POST data submissions for given actions.
 *
 * Every action is expected to return the slug for which page to redirect
 * the browser to after processing is complete.
 *
 * @phpstan-type VotePostPayload array{
 *   a:int,
 *   b:int,
 *   winner?:string,
 *   suppress_keyword?:int,
 *   veto_domain?:int
 * }
 */
class PostDataProcessor
{
    use Redirector;

    protected DB $db;
    protected DomainGen $gen;
    protected PricingApi $pricingApi;

    public function __construct()
    {
        $this->db = new DB(Config::read('DB_FILE'));
        $this->gen = new DomainGen($this->db);
        $this->pricingApi = new PricingApi(
            Config::read('PORKBUN_API_TOKEN') ?? '',
            Config::read('PORKBUN_SECRET_API_TOKEN') ?? '',
        );
    }

    /**
     * Process the provided data using the provided action.
     *
     * @param string $action
     * @param array<mixed> $data
     * @return void
     */
    public function handle(string $action, array $data): void
    {
        if (!in_array($action, get_class_methods($this))) {
            $h = Helper::h($action);
            Flash::add("Requested action not found: <code>{$h}</code>");
            $this->redirToAction('vote');
            exit;
        }

        $this->redirToAction($this->$action($data));
    }

    /**
     * Process a single vote submission.
     *
     * @param VotePostPayload $data
     * @return string
     */
    protected function vote(array $data): string
    {
        // Process super actions first.
        if (array_key_exists('super', $_GET)) { // Eww.
            if (($suppressKeywordId = $data['suppress_keyword'] ?? false)
                && ($keywordId = $this->db->suppressKeywordByVoteId((int)$suppressKeywordId))
            ) {
                Flash::add("Keyword ID <code>{$keywordId}</code> has been disabled.", 'info');
                return 'vote';
            }

            if (($vetoDomainId = $data['veto_domain'] ?? false)
                && $this->db->vetoDomain((int)$vetoDomainId)
            ) {
                Flash::add("Domain ID <code>{$vetoDomainId}</code> has been disabled.", 'info');
            }

            return 'vote';
        }

        // Process a normal vote.
        try {
            [$winner, $loser] = $this->_validateVotes($data);
        } catch (InvalidArgumentException $e) {
            return 'vote';
        }

        // Save for diff calcs later.
        [$winnerOldElo, $loserOldElo] = [$winner->elo_score, $loser->elo_score];

        // Calculate changes.
        [$winnerNewElo, $loserNewElo] = $this->gen->updateElos($winnerOldElo, $loserOldElo);

        // Update the entities.
        $winner->elo_score = $winnerNewElo;
        $winner->vote_count += 1;
        $loser->elo_score = $loserNewElo;
        $this->db->updateVote($winner);
        $this->db->updateVote($loser);
        dd($this->db->getVoteById($winner->id));
        [$winnerDiff, $loserDiff] = [$winnerNewElo - $winnerOldElo, $loserNewElo - $loserOldElo];

        Flash::add("
            <code>{$winner}</code> wins (📈 +{$winnerDiff})!
            <code>{$loser}</code> loses (📉 {$loserDiff}).
        ", 'info');
        return 'vote';
    }

    /**
     * Validate voting data submissions.
     *
     * @param VotePostPayload $data
     * @return list<Domain> A pair of Domain entities from the DB.
     * @throws InvalidArgumentException When any error condition is encountered.
     */
    protected function _validateVotes(array $data): array
    {
        $err = function (string $msg) {
            Flash::add($msg, 'danger');
            throw new InvalidArgumentException($msg);
        };

        $winnerLetter = $data['winner'] ?? 'invalid';
        if (!in_array($winnerLetter, ['a', 'b'])) {
            $err('Invalid vote submission. Winner missing in payload.');
        }

        $loserLetter = ($winnerLetter === 'a' ? 'b' : 'a');

        $winnerId = (int)($data[$winnerLetter] ?? 0);
        $loserId = (int)($data[$loserLetter] ?? 0);

        if (!$winnerId || !$loserId) {
            $err('Invalid vote submission. Domain ID(s) missing in payload.');
        }

        $winner = $this->db->getVoteById($winnerId);
        $loser = $this->db->getVoteById($loserId);

        if (!$winner || !$loser) {
            $err('Invalid vote submission. DB record(s) missing.');
        }

        return [$winner, $loser];
    }

    /**
     * Process the POSTed keyword, sanitize, and add to DB.
     *
     * @param array{keyword:string,description:string} $data
     * @return string
     */
    protected function add_keyword(array $data): string
    {
        $kw = Domain::sanitizeKeyword($data['keyword'] ?? '');
        $desc = mb_trim($data['description'] ?? '');
        $escapedKW = $kw ?: '(empty string)';

        if (!Domain::validKeyword($kw)) {
            $link = Helper::regex101Link($kw);
            Flash::add(
                <<<EOS
                    Invalid keyword: <code>{$escapedKW}</code>. Must <a href="{$link}" target="_blank" class="alert-link">match this pattern</a>.
                EOS,
                'danger'
            );
        } elseif (!$this->db->addKeyword($kw, $desc)) {
            Flash::add("Keyword <code>{$kw}</code> already exists.", 'warning');
        } else {
            Flash::add("Keyword <code>{$kw}</code> added.", 'success');
        }

        return 'add_keyword';
    }

    /**
     * Validate, sanitize, and add the POSTed keyword list and associated
     * descriptions to the DB.
     *
     * @param array{tsv:string} $data
     * @return string
     */
    protected function bulk_keywords(array $data): string
    {
        /** @var list<string> $rawPairs */
        $rawPairs = preg_split("/\\r?\\n/", $data['tsv'] ?? '');
        foreach ($rawPairs as $row) {
            // Handle no tab and/or description.
            [$keyword, $description] = explode("\t", $row) + ['', ''];
            // This will conveniently sanitize for us and set flash
            // messages for every row.
            $this->add_keyword(compact('keyword', 'description'));
        }

        return 'bulk_keywords';
    }

    /**
     * Validate, sanitize, and add the POSTed domain attributes to the DB.
     *
     * @param array{
     *   prefix:string,
     *   keyword:string,
     *   suffix:string,
     *   tld:string
     * } $data
     * @return string
     */
    protected function add_domain(array $data): string
    {
        $domain = Domain::fromPost($data);
        $domain->vote_count += 1; // The act of suggesting a domain counts as a vote.
        $result = $this->db->addDomain($domain);
        if ($result) {
            Flash::add("Domain added with an initial vote! <code>{$result}</code> (ID: <code>{$result->id}</code>)", 'success');
        } else {
            Flash::add("Domain <code>{$domain}</code> could not be added.", 'danger');
        }
        return 'add_domain';
    }

    /**
     * Perform the availability API call and update the DB with results.
     *
     * @param array<mixed> $_ Not used.
     * @return string
     */
    protected function update_availability(array $_): string
    {
        $LIMIT = 20;
        // All in seconds.
        $AVG_CALL_DURATION = 15;
        $DELAY = Config::read('RATE_LIMIT_DEFAULT_SECS');

        $domains = $this->db->domainsNeedingAvailability(); // TODO: Add a limit argument.
        if (count($domains) > $LIMIT) {
            $domains = array_slice($domains, 0, $LIMIT);
        }
        // Try to avoid PHP's default 30sec execution time limit.
        set_time_limit(($AVG_CALL_DURATION + $DELAY) * $LIMIT);

        foreach ($domains as $domain) {
            $res = $this->pricingApi->getDomainPricing($domain);
            if ($res) {
                $domain->available = $res['available'];
                $domain->year1_price = $res['year1_price'];
                $domain->renewal_price = $res['renewal_price'];
                $this->db->updateVote($domain);
                Flash::add(
                    sprintf(
                        'Availability updated for <code>%s</code> (<span class="badge text-bg-%s">%savailable</span>, 1st year: $%0.2f, Renewal: $%0.2f)',
                        $domain,
                        ($domain->available ? 'success' : 'info'),
                        ($domain->available ? '' : 'not '),
                        $domain->year1_price,
                        $domain->renewal_price,
                    ),
                    'success',
                );
            } else {
                Flash::add(
                    sprintf('Availability update failed for <code>%s</code> (%s: %s)',
                        $domain,
                        $res->code,
                        $res->message,
                    ),
                    'danger',
                );
            }
            sleep($res['ttlRemaining'] ?? $DELAY); // Try to avoid rate limits.
        }

        return 'update_availability';
    }
}

/**
 * Static $_SESSION wrapper.
 *
 * Save messages to the PHP session and consume them (display and delete)
 * on next page load.
 */
class Flash
{
    // TODO: Gamify voting by showing a running "streak" total. `if (_SESSION[streak_count]++ % 100 == 0) Flash::add(streak_count streak!)`

    /**
     * Retrieve all set flash messages and clear them.
     *
     * @return array<array{msg:string,style:string}>
     */
    public static function get(): array
    {
        $msgs = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $msgs;
    }

    public static function add(string $msg, string $class = 'info'): void
    {
        $_SESSION['_flash'][] = [
            'msg' => $msg,
            'style' => $class,
        ];
    }

    /**
     * Retrieve all set error messages and clear them.
     *
     * @return array{msg:string,trace:string}
     */
    public static function errors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $errors;
    }

    public static function error(Throwable $e): void
    {
        $_SESSION['_errors'][] = [
            'msg' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
    }
}

/**
 * Static presentation helpers.
 *
 * Keeps dynamic HTML generation consolidated in this class.
 */
class Helper
{
    const string PRICE_LOOKUP_URL = 'https://porkbun.com/checkout/search?q=%s';

    const array NAV = [
        'vote' => ['🗳️ Vote', 'primary'],
        'leaderboard' => ['🏅 Leaderboard', 'info'],
        'add_domain' => ['🔗 Add Domain', 'secondary'],
        'add_keyword' => ['🆕 Add Keyword', 'secondary'],
        // Make these "hidden" actions for now.
        // 'bulk_keywords' => ['📋 Add Many Keywords', 'warning'],
        // 'update_availability' => ['💵 Update Domain Availability and Pricing', 'warning'],
        // 'stats' => ['📊 Voting Stats', 'info'],
    ];

    public static function h(string $s): string
    {
        return htmlspecialchars($s);
    }

    public static function showFlash(): string
    {
        $out = '';
        foreach (Flash::get() as $m) {
            $msg = $m['msg'];
            $style = $m['style'] ?? 'info';
            $out .= <<<EOFLASH
                <div class="alert alert-{$style}" role="alert">{$msg}</div>
            EOFLASH;
        }
        return $out;
    }

    /**
     * Generate a URL for exploring the regex failure on the string $test.
     *
     * @ref https://github.com/firasdib/Regex101/wiki/FAQ#how-to-prefill-the-fields-on-the-interface-via-url
     * @param string $test
     * @return string
     */
    public static function regex101Link(string $test): string
    {
        $escapedPattern = urlencode(Domain::VALID_HOSTNAME_PATTERN);
        $escapedTest = urlencode($test);
        return mb_trim(
            <<<EOLINK
                https://regex101.com/?regex={$escapedPattern}&testString={$escapedTest}&flags=i
            EOLINK
        );
    }

    public static function nav(string $active = 'vote'): string
    {
        $highlight = function($current, $active, $defaultClass = 'btn-light') {
            return ($current === $active ? "btn-{$defaultClass} active fw-bold" : "btn-outline-{$defaultClass}");
        };

        $links = '';
        foreach (self::NAV as $action => $opts) {
            [$title, $def] = $opts;
            $link = self::link(
                $action,
                $title,
                [
                    'btn',
                    'text-nowrap',
                    'w-100',
                    'w-sm-auto',
                    $highlight($action, $active, $def),
                ],
            );
            $links .= <<<EOLINK
                <li class="navbar-item mb-1">
                    {$link}
                </li>
            EOLINK;
        }

        return <<<EONAV
            <ul class="navbar-nav d-flex flex-wrap flex-sm-row flex-grow-1 gap-1 ms-auto justify-content-end">
                {$links}
            </ul>
        EONAV;
    }

    public static function p(string $content): string
    {
        return <<<EOP
            <p>{$content}</p>
        EOP;
    }

    /**
     * Convenience helper for generating a button-like navigation link in
     * body text.
     *
     * @param string $action
     * @param string $title
     * @return string
     */
    public static function textLink(string $action, string $title): string
    {
        return self::link(
            $action,
            $title,
            ['btn', 'btn-sm', 'btn-outline-dark', 'align-baseline'],
        );
    }

    /**
     * Convenience helper for generating navigation links.
     *
     * @param string $action Just the action string. See ::NAV.
     * @param string $title
     * @param list<string> $classes
     * @param array<mixed> $params
     * @return string
     */
    public static function link(
        string $action,
        string $title = '',
        array $classes = ['secondary'],
        array $params = [],
    ): string
    {
        return self::a(
            '?',
            ($title ?: self::NAV[$action][0]),
            $classes,
            ['action' => $action] + $params,
        );
    }

    /**
     * Create a hyperlink tag.
     *
     * @param string $url
     * @param string $title
     * @param list<string> $classes
     * @param list<string> $params
     * @return string
     */
    public static function a(
        string $url,
        string $title,
        array $classes = [],
        array $params = [],
    ): string
    {
        $c = mb_trim(implode(' ', $classes));
        $urlBase = new Url((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
        $href = new Url($url, $urlBase);
        print_r($href);
        if (count($params) > 0) {
            $existing = [];
            parse_str($href->getQuery(), $existing);
            $href = $href->withQuery(http_build_query($params + $existing));
        }

        return mb_trim(<<<EOA
            <a href="{$href->toUnicodeString()}" class="{$c}">{$title}</a>
        EOA);
    }

    public static function badge(string $content, string $class = 'secondary'): string
    {
        return mb_trim(<<<EOS
            <span class="badge text-bg-{$class}">{$content}</span>
        EOS);
    }

    /**
     * Construct a standardized naviation URL for the provided nav action name.
     *
     * @param string $action
     * @param array<string,mixed> $params
     * @return string
     */
    public static function navUrl(string $action, array $params = []): string
    {
        $query =  http_build_query([
            'action' => $action,
        ] + $params + $_GET); // This is a bit hacky.

        return "?{$query}";
    }

    public static function domainWithPriceLink(string $domain): string
    {
        $url = sprintf(self::PRICE_LOOKUP_URL, $domain);
        $link = self::a($url, '$');

        return <<<EOP
            <code>{$domain}</code> <span class="fw-lighter fst-italic">({$link})</span>
        EOP;
    }

    /**
     * Produce an HTML <select> element.
     *
     * @param string $name Input name for form submission.
     * @param string $label
     * @param array<string>|array<string,string>|array<string,array{label:string,description:string}> $options
     *   Can take three forms:
     *     ['opt1', 'opt2', 'opt3']
     *
     *     [ 'opt1' => 'Option 1', 'opt2' => 'Option 2' ]
     *
     *     [
     *         'opt1' => [label => 'Option 1', description => 'This is option 1.'],
     *         'opt2' => [...],
     *     ]
     * @param string $prefix Bootstrap text element to apply in front of
     *   the <select> input.
     * @return string Completed `<select>...</select>` element.
     */
    public static function select(
        string $name,
        string $label,
        array $options,
        string $prefix = '',
    ): string
    {
        $select = '';
        foreach ($options as $i => $attrs) {
            // 'opt1' => [label => 'Option 1', description => 'This is option 1.']
            if (is_array($attrs)) {
                $lab = self::h($attrs['label'] ?? $i);
                $desc = self::h($attrs['description'] ?? '');
                $val = self::h($i);
            } elseif (is_string($i) && is_string($attrs)) { // 'opt1' => 'Option 1'
                $lab = self::h($attrs);
                $desc = '';
                $val = self::h($attrs);
            } else { // 0 => 'opt1'
                $lab = self::h($attrs);
                $desc = '';
                $val = self::h($attrs);
            }
            $select .= <<<EOOPT
                <option value="{$val}" data-description="{$desc}">{$lab}</option>
            EOOPT;
        }
        $select = <<<EOS
            <select name="{$name}" class="form-select" onchange="event.target.parentElement.parentElement.querySelector('.form-text').textContent = event.target.selectedOptions[0].dataset.description;">
                {$select}
            </select>
        EOS;

        if (!empty($prefix)) {
            $select = <<<EOG
                <div class="input-group-text">{$prefix}</div>
                {$select}
            EOG;
        }
        $label = self::h($label);

        return <<<EOSEL
            <label for="{$name}" class="form-label">{$label}</label>
            <div class="input-group">
                {$select}
            </div>
            <div class="form-text" ></div>
        EOSEL;
    }

    /**
     * Build an html <tr> element string.
     *
     * @param list<scalar> $cols
     * @param string $class
     * @return string
     */
    public static function tr(array $cols, string $class = ''): string
    {
        $tr = '';
        foreach ($cols as $col) {
            $tr .= self::td(
                $col,
                is_numeric($col) ? 'text-end' : '', // What a bodge!
            );
        }

        return <<<EOTR
            <tr class="{$class}">
                {$tr}
            </tr>
        EOTR;
    }

    protected static function td(
        string|int|float|bool $content,
        string $class = '',
    ): string
    {
        return mb_trim(
            <<<EOTD
                <td class="{$class}">{$content}</td>
            EOTD
        );
    }
}

/**
 * main()
 */

session_start();

$action = $_GET['action'] ?? 'vote';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    (new PostDataProcessor())->handle($action, $_POST);
}

// Collect page content here, so that Pages can use Flash::add().
$content = (new Pages())->dispatch($action, $_GET);

/**
 * Page Layout
 */
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <title><?= Config::read('PAGE_TITLE') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <style>
        div[aria-label="Super functions"] button:first-of-type { border-top-left-radius: 0; }
        div[aria-label="Super functions"] button:last-of-type { border-bottom-right-radius: 0; }
    </style>
</head>
<body class="d-flex flex-column vh-100">
    <nav class="navbar bg-body-tertiary border-bottom p-3 w-100 align-items-stretch justify-content-between">
        <?= Helper::link('vote', Config::read('PAGE_TITLE'), ['navbar-brand', 'text-wrap']) ?>
        <?= Helper::nav($action) ?>
    </nav>

    <div class="container py-4 d-flex flex-column flex-grow-1">
        <div class="flash">
            <?= Helper::showFlash() ?>
        </div>

        <main class="flex-grow-1">
            <?= $content ?>
        </main>

        <footer class="mt-4">
            <hr>
            <small>Copyright &copy; 2026 <?= Helper::a('https://github.com/beporter', 'Brian Porter') ?></small>
        </footer>
    </div>
</body>
</html>
