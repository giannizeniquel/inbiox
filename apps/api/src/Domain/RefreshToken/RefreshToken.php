<?php

declare(strict_types=1);

namespace App\Domain\RefreshToken;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
class RefreshToken implements RefreshTokenInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128, unique: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $username = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $valid = null;

    public static function createForUserWithTtl(string $refreshToken, UserInterface $user, int $ttl): RefreshTokenInterface
    {
        $valid = new \DateTime();
        if ($ttl > 0) {
            $valid->modify('+' . $ttl . ' seconds');
        } elseif ($ttl < 0) {
            $valid->modify($ttl . ' seconds');
        }

        $model = new self();
        $model->setRefreshToken($refreshToken);
        $model->setUsername(method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : $user->getUsername());
        $model->setValid($valid);

        return $model;
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken($refreshToken = null): static
    {
        if (null === $refreshToken || '' === $refreshToken) {
            $refreshToken = bin2hex(random_bytes(64));
        }

        $this->refreshToken = $refreshToken;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername($username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getValid(): ?\DateTimeInterface
    {
        return $this->valid;
    }

    public function setValid($valid): static
    {
        $this->valid = $valid;

        return $this;
    }

    public function isValid(): bool
    {
        return null !== $this->valid && $this->valid >= new \DateTime();
    }

    public function __toString(): string
    {
        return $this->refreshToken ?? '';
    }
}
