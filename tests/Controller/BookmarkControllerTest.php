<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Calls the bookmark endpoints through the full Symfony stack against the PostgreSQL test database.
 */
final class BookmarkControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // Every test starts from empty tables, so tests never depend on each other.
        $this->entityManager->getConnection()->executeStatement('TRUNCATE bookmark, app_user RESTART IDENTITY CASCADE');
    }

    public function testCreateReturns201WithTheBookmarkAndItsLocation(): void
    {
        $token = $this->createUser('ana@example.com');

        $this->postBookmark($token, ['type' => 'apod', 'date' => '2020-11-10', 'title' => 'A galaxy']);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('apod', $body['type']);
        self::assertSame('apod:2020-11-10', $body['discoveryKey']);
        self::assertResponseHeaderSame('Location', '/api/bookmarks/'.$body['id']);
    }

    public function testAPersonIsBookmarkedWithAKeyBuiltFromTheDayAndTheBirthText(): void
    {
        $token = $this->createUser('ana@example.com');
        $payload = ['type' => 'person', 'date' => '2020-11-10', 'title' => 'A fake person, writer'];

        $this->postBookmark($token, $payload);

        self::assertResponseStatusCodeSame(201);
        $expectedKey = 'person:11-10:'.substr(sha1('A fake person, writer'), 0, 8);
        self::assertSame($expectedKey, $this->jsonBody()['discoveryKey']);

        // The same person on the same day is a duplicate.
        $this->postBookmark($token, $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheSameDiscoveryCannotBeBookmarkedTwiceByTheSameUser(): void
    {
        $token = $this->createUser('ana@example.com');
        $payload = ['type' => 'apod', 'date' => '2020-11-10', 'title' => 'A galaxy'];

        $this->postBookmark($token, $payload);
        $this->postBookmark($token, $payload);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAnotherUserCanBookmarkTheSameDiscovery(): void
    {
        $payload = ['type' => 'apod', 'date' => '2020-11-10', 'title' => 'A galaxy'];

        $this->postBookmark($this->createUser('ana@example.com'), $payload);
        $this->postBookmark($this->createUser('beto@example.com'), $payload);

        self::assertResponseStatusCodeSame(201);
    }

    public function testAnInvalidBodyReturns422WithTheViolations(): void
    {
        $token = $this->createUser('ana@example.com');

        $this->postBookmark($token, ['type' => 'planet', 'date' => '2020-11-10', 'title' => ' ']);

        self::assertResponseStatusCodeSame(422);
        $fields = array_column($this->jsonBody()['violations'], 'field');
        self::assertContains('type', $fields);
        self::assertContains('title', $fields);
    }

    public function testRequestsWithoutAValidTokenReturn401(): void
    {
        $this->client->request('GET', '/api/bookmarks');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/bookmarks', server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-token']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testListIsPaginatedAndOnlyShowsTheUsersOwnBookmarks(): void
    {
        $ana = $this->createUser('ana@example.com');
        $beto = $this->createUser('beto@example.com');

        // 12 bookmarks on 12 different days, so page 1 has 10 items and page 2 has 2.
        for ($dayOfMonth = 1; $dayOfMonth <= 12; ++$dayOfMonth) {
            $this->postBookmark($ana, ['type' => 'apod', 'date' => \sprintf('2020-11-%02d', $dayOfMonth), 'title' => 'Day '.$dayOfMonth]);
        }
        $this->postBookmark($beto, ['type' => 'apod', 'date' => '2020-11-01', 'title' => 'Beto picture']);

        $this->getAs($ana, '/api/bookmarks?page=2');
        self::assertResponseIsSuccessful();
        $page = $this->jsonBody();
        self::assertSame(2, $page['page']);
        self::assertSame(12, $page['total']);
        self::assertSame(2, $page['totalPages']);
        self::assertCount(2, $page['items']);

        $this->getAs($beto, '/api/bookmarks');
        $betoPage = $this->jsonBody();
        self::assertSame(1, $betoPage['total']);
        self::assertSame('Beto picture', $betoPage['items'][0]['title']);
    }

    public function testDeleteRemovesTheUsersOwnBookmark(): void
    {
        $token = $this->createUser('ana@example.com');
        $id = $this->createBookmark($token);

        $this->client->request('DELETE', '/api/bookmarks/'.$id, server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(204);

        $this->getAs($token, '/api/bookmarks/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAUserCannotSeeOrDeleteAnotherUsersBookmark(): void
    {
        $ana = $this->createUser('ana@example.com');
        $beto = $this->createUser('beto@example.com');
        $id = $this->createBookmark($ana);

        // 404 instead of 403, so other users cannot even learn that the id exists.
        $this->getAs($beto, '/api/bookmarks/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/bookmarks/'.$id, server: $this->authHeader($beto));
        self::assertResponseStatusCodeSame(404);

        $this->getAs($ana, '/api/bookmarks/'.$id);
        self::assertResponseIsSuccessful();
    }

    /**
     * Saves a user directly in the database, the same way the almanac:user:create command does.
     *
     * @param string $email unique e-mail of the new user
     * @return string the plain access token to send as a Bearer token
     */
    private function createUser(string $email): string
    {
        $token = bin2hex(random_bytes(32));
        $this->entityManager->persist(new User($email, User::hashToken($token), new \DateTimeImmutable()));
        $this->entityManager->flush();

        return $token;
    }

    /**
     * @param string $token plain access token of the owner
     * @return int the id of a new APOD bookmark
     */
    private function createBookmark(string $token): int
    {
        $this->postBookmark($token, ['type' => 'apod', 'date' => '2020-11-10', 'title' => 'A galaxy']);
        self::assertResponseStatusCodeSame(201);

        return $this->jsonBody()['id'];
    }

    /**
     * @param string $token plain access token
     * @param array<string, mixed> $payload bookmark fields sent as JSON
     */
    private function postBookmark(string $token, array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/bookmarks',
            server: $this->authHeader($token) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param string $token plain access token
     * @param string $uri path and query string to request
     */
    private function getAs(string $token, string $uri): void
    {
        $this->client->request('GET', $uri, server: $this->authHeader($token));
    }

    /**
     * @param string $token plain access token
     * @return array<string, string> the Authorization header in the format the test client expects
     */
    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /**
     * @return array<string, mixed> the decoded JSON body of the last response
     */
    private function jsonBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
