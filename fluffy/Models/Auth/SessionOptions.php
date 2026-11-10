<?php

namespace Fluffy\Models\Auth;

/**
 * How AuthorizationService::authorizeUserWith() makes a session, for sign-ins that do not follow the
 * remember-me rule: an organisation's sign-in (SSO) sets its own length, marks the session with its
 * connection and, when it can renew with the provider, when that check is next due.
 */
class SessionOptions
{
    /**
     * @param int $lifetime seconds until the session's hard end (UserToken.Expire)
     * @param bool $persistent the cookie survives a browser restart (expires with the session);
     *   false = a browser-session cookie, like a sign-in without remember-me
     * @param int|null $ssoConnectionId the app's SSO connection; rotation keeps it
     * @param int|null $ssoCheckDue unix seconds when ISessionRenewer must next confirm the session
     */
    public function __construct(
        public int $lifetime,
        public bool $persistent = true,
        public ?int $ssoConnectionId = null,
        public ?int $ssoCheckDue = null,
    ) {}
}
