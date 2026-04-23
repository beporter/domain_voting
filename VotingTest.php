<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class DomainTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class DBTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class AvailabilityApiTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class DomainGenTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

// trait Redirector

final class PagesTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class PostDataProcessorTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class FlashTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class HelperTest extends TestCase
{
    public function testSomething(): void
    {
        $string = 'user@example.org';
        $email = Email::fromString($string);

        $this->assertSame($string, $email->asString());
    }
}

final class VotingTest extends TestCase
{
    public function testAll(): void
    {
        // TODO: `ob_start(); include_once('voting.php'); $page = ob_get_clean();
        // TODO: Aggregate all the individual test classes above since PHPUnit will run this one by default.
        // ConfigTest
        // DomainTest
        // DBTest
        // AvailabilityApiTest
        // DomainGenTest
        // RedirectorTest
        // PagesTest
        // PostDataProcessorTest
        // FlashTest
        // HelperTest
    }
}
