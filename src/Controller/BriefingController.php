<?php

declare(strict_types=1);

namespace App\Controller;

use App\Briefing\BriefingDateRange;
use App\Briefing\BriefingProvider;
use App\Http\ProblemResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Exposes the aggregated daily briefing.
 */
final class BriefingController extends AbstractController
{
    /**
     * First date with a NASA APOD picture.
     */
    private const MIN_DATE = BriefingDateRange::MIN_DATE;

    /**
     * @param BriefingProvider $provider returns cached or freshly fetched briefings
     * @param ValidatorInterface $validator Symfony Validator, checks the date input
     * @param ProblemResponseFactory $problems builds RFC 7807 error responses
     */
    public function __construct(
        private readonly BriefingProvider $provider,
        private readonly ValidatorInterface $validator,
        private readonly ProblemResponseFactory $problems,
    ) {
    }

    /**
     * Returns the briefing for one date (YYYY-MM-DD).
     *
     * The date is validated before any upstream call, so invalid input
     * never reaches the sources nor the cache table.
     *
     * @param string $date the date taken from the URL
     * @return JsonResponse the briefing, or an RFC 7807 problem on invalid input
     */
    #[Route('/api/briefings/{date}', name: 'briefing_show', methods: ['GET'])]
    public function show(string $date): JsonResponse
    {
        // 1. Format and real calendar date: rejects "abc" and "2020-02-31".
        $violations = $this->validator->validate($date, new Assert\Date(
            message: 'The date must be a real calendar date in YYYY-MM-DD format.',
        ));
        if (\count($violations) > 0) {
            return $this->problems->create(
                Response::HTTP_BAD_REQUEST,
                'Invalid date',
                (string) $violations->get(0)->getMessage(),
            );
        }

        $utc = new \DateTimeZone('UTC');
        $requestedDate = new \DateTimeImmutable($date, $utc);
        $latestDate = BriefingDateRange::maxDate();

        // 2. Allowed range: from the first APOD picture to the forecast limit.
        $violations = $this->validator->validate($requestedDate, new Assert\Range(
            notInRangeMessage: \sprintf('The date must be between %s and %s.', self::MIN_DATE, $latestDate),
            min: self::MIN_DATE,
            max: $latestDate,
        ));
        if (\count($violations) > 0) {
            return $this->problems->create(
                Response::HTTP_BAD_REQUEST,
                'Date out of range',
                (string) $violations->get(0)->getMessage(),
            );
        }

        // 3. Valid date: serve it from the cache or the sources.
        $briefing = $this->provider->get($requestedDate);

        return $this->json([
            'date' => $briefing->getDate()->format('Y-m-d'),
            'sources' => $briefing->getSources(),
            'missing' => $briefing->getMissing(),
            'fetchedAt' => $briefing->getFetchedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
