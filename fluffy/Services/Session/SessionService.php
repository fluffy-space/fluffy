<?php

namespace Fluffy\Services\Session;

use Fluffy\Data\Entities\Auth\SessionEntity;
use Fluffy\Data\Entities\Auth\SessionEntityMap;
use Fluffy\Data\Repositories\SessionRepository;
use Fluffy\Domain\Message\HttpContext;

class SessionService
{
    const COOKIE_NAME = 'SID';

    protected ?string $SID;
    protected ?SessionEntity $SessionEntity;

    public function __construct(private ?HttpContext $httpContext, private SessionRepository $sessionRepository) {}

    public function getSession(): ?SessionEntity
    {
        if ($this->SID ?? ($this->SID = $this->httpContext->request->getCookie(self::COOKIE_NAME))) {
            return $this->SessionEntity ?? ($this->SessionEntity = $this->sessionRepository->find(SessionEntityMap::PROPERTY_HashId, $this->SID));
        }
        return null;
    }

    protected function createSession(?int $userId = null): SessionEntity
    {
        $this->SessionEntity = SessionEntity::getNew();
        $this->SessionEntity->UserId = $userId;
        $this->SID = $this->SessionEntity->HashId;
        $this->sessionRepository->create($this->SessionEntity);
        return $this->SessionEntity;
    }

    public function startSession(?int $userId = null): SessionEntity
    {
        $session = $this->getSession() ?? $this->createSession($userId);
        $this->httpContext->response->setCookie(self::COOKIE_NAME, $session->HashId, time() + 60 * 60 * 24 * 30, '/', '', 1, 1);
        return $session;
    }
}
