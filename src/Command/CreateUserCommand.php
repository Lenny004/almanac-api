<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates an API user and prints its access token once.
 * Only a hash of the token is stored, so the printed value cannot be recovered later.
 */
#[AsCommand(name: 'almanac:user:create', description: 'Create an API user and print its access token')]
final class CreateUserCommand
{
    /**
     * Length of the random token in bytes (64 hex characters once encoded).
     */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Runs the command: validate the e-mail, create the user, print the token.
     *
     * @param SymfonyStyle $io    console helper to print formatted output
     * @param string       $email e-mail of the new user
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail of the new user')] string $email,
    ): int {
        // E-mails are case-insensitive: store them lowercased so Ana@x.com and ana@x.com are the same user.
        $email = mb_strtolower(trim($email));

        $emailError = $this->findEmailError($email);
        if (null !== $emailError) {
            $io->error($emailError);

            return Command::FAILURE;
        }

        $plainToken = $this->generateToken();
        $this->createUser($email, $plainToken);

        $io->success(\sprintf('User "%s" created.', $email));
        $io->writeln('Access token (shown only once, store it safely):');
        $io->writeln($plainToken);

        return Command::SUCCESS;
    }

    /**
     * Checks that the e-mail is valid and not used by another user.
     *
     * @param string $email the e-mail to check
     *
     * @return string|null an error message, or null when the e-mail is fine
     */
    private function findEmailError(string $email): ?string
    {
        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if (\count($violations) > 0) {
            return \sprintf('Invalid e-mail: %s', $violations[0]->getMessage());
        }

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existingUser) {
            return \sprintf('A user with the e-mail "%s" already exists.', $email);
        }

        return null;
    }

    /**
     * Generates a random, impossible-to-guess access token.
     *
     * @return string a 64-character hexadecimal token
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Saves a new user, storing only the hash of its token.
     *
     * @param string $email      e-mail of the new user
     * @param string $plainToken the token that will be shown to the user
     */
    private function createUser(string $email, string $plainToken): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $user = new User($email, User::hashToken($plainToken), $now);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}