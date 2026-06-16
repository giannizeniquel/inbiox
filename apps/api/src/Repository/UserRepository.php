<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Loads a user by email (identifier) for Symfony authentication.
     *
     * @throws UserNotFoundException if no active user is found
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneBy(['email' => $identifier]);

        if (null === $user) {
            throw new UserNotFoundException(\sprintf('User with email "%s" not found.', $identifier));
        }

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findActiveByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email, 'isActive' => true]);
    }

    /**
     * @return User[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
