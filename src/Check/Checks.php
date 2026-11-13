<?php

declare(strict_types=1);

namespace App\Check;

/**
 * Names of the automatic checks of concept 7.2 (and 15.1 for atproto).
 */
final class Checks
{
    public const HTTPS = 'https';
    public const NODEINFO = 'nodeinfo';
    public const TERMS = 'terms';
    public const ABUSE_CONTACT = 'abuse_contact';
    public const INSTANCE_SIGNUP = 'instance_signup';
    public const BLOCKLIST = 'blocklist';
    public const HEARTBEATS = 'heartbeats';
    public const PROFILE = 'profile';
    public const ATPROTO = 'atproto';

    /** All must pass before an application goes to the curators. */
    public const BEFORE_REVIEW = [self::HTTPS, self::NODEINFO, self::TERMS, self::ABUSE_CONTACT, self::INSTANCE_SIGNUP, self::BLOCKLIST, self::HEARTBEATS, self::PROFILE];

    /** Repeated failure pauses a listed server (concept 6.4). */
    public const CRITICAL = [self::HTTPS, self::NODEINFO, self::BLOCKLIST];

    public const LABELS = [
        self::HTTPS => 'HTTPS with a valid certificate',
        self::NODEINFO => 'Nodeinfo names Aloha',
        self::TERMS => 'Terms, privacy policy and rules',
        self::ABUSE_CONTACT => 'Abuse contact',
        self::INSTANCE_SIGNUP => 'Signup state matches /api/v2/instance',
        self::BLOCKLIST => 'Not on a block list',
        self::HEARTBEATS => 'Three heartbeats on time',
        self::PROFILE => 'Profile submitted',
        self::ATPROTO => 'Bluesky handles work',
    ];
}
