<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route; // Angepasst: Nutzt Attribute-Namespace
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Psr\Log\LoggerInterface;
use OpenApi\Attributes as OA; // Angepasst: Nutzt Attributes-Namespace

class ContactController extends AbstractController
{
    private LoggerInterface $logger;
    private string $fromEmail;
    private string $toEmail;

    public function __construct(LoggerInterface $logger, string $contactFromEmail = 'noreply@jenssmit.de', string $contactToEmail = 'info@jenssmit.de')
    {
        $this->logger = $logger;
        $this->fromEmail = $contactFromEmail;
        $this->toEmail = $contactToEmail;
    }

    // Die alten DocBlock-Annotationen wurden durch PHP 8 Attributes ersetzt
    #[Route('/api/contact', name: 'api_contact_submit', methods: ['POST'])]
    #[OA\Post(
        path: "/api/contact",
        summary: "Kontaktformular absenden",
        tags: ["Kontakt"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "subject", "message"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Max Mustermann"),
                    new OA\Property(property: "email", type: "string", format: "email", example: "max@example.com"),
                    new OA\Property(property: "subject", type: "string", example: "Frage zum Produkt"),
                    new OA\Property(property: "message", type: "string", example: "Hallo, ich habe eine Frage..."),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Kontaktanfrage erfolgreich versendet",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Ihre Nachricht wurde erfolgreich gesendet.")
                    ]
                )
            ),
            new OA\Response(
            response: 400,
            description: "Validierungsfehler oder ungültige Daten",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "Validierungsfehler"),
                    new OA\Property(
                        property: "errors",
                        type: "object",
                        // FINALE KORREKTUR: OA\AdditionalProperties als Wrapper mit dem Typ-Argument verwenden.
                        additionalProperties: new OA\AdditionalProperties(
                            type: "string" // Definiert den Typ des Werts für zusätzliche Schlüssel als String
                        )
                    )
                ]
            )
        ),
            new OA\Response(
                response: 500,
                description: "Serverfehler beim Senden der E-Mail",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Beim Senden der Nachricht ist ein Fehler aufgetreten.")
                    ]
                )
            ),
        ]
    )]
    public function submitContact(Request $request, MailerInterface $mailer, ValidatorInterface $validator): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Invalid JSON data received for contact form.');
                return new JsonResponse(['message' => 'Ungültige JSON-Daten.'], Response::HTTP_BAD_REQUEST);
            }

            $inputBag = new Assert\Collection([
                'name' => [
                    // FIX für NotBlank (vorher: new Assert\NotBlank(['message' => ...]))
                    new Assert\NotBlank(
                        message: 'Der Name darf nicht leer sein.'
                    ),
                    // FIX für Length (vorher: new Assert\Length(['min' => ..., 'max' => ..., ...]))
                    new Assert\Length(
                        min: 2,
                        max: 100,
                        minMessage: 'Der Name ist zu kurz.',
                        maxMessage: 'Der Name ist zu lang.'
                    ),
                ],
                'email' => [
                    // FIX für NotBlank
                    new Assert\NotBlank(
                        message: 'Die E-Mail darf nicht leer sein.'
                    ),
                    // FIX für Email (vorher: new Assert\Email(['message' => ...]))
                    new Assert\Email(
                        message: 'Die E-Mail-Adresse ist ungültig.'
                    ),
                ],
                'subject' => [
                    // FIX für NotBlank
                    new Assert\NotBlank(
                        message: 'Der Betreff darf nicht leer sein.'
                    ),
                    // FIX für Length
                    new Assert\Length(
                        min: 3,
                        max: 200,
                        minMessage: 'Der Betreff ist zu kurz.',
                        maxMessage: 'Der Betreff ist zu lang.'
                    ),
                ],
                'message' => [
                    // FIX für NotBlank
                    new Assert\NotBlank(
                        message: 'Die Nachricht darf nicht leer sein.'
                    ),
                    // FIX für Length
                    new Assert\Length(
                        min: 10,
                        max: 5000,
                        minMessage: 'Die Nachricht ist zu kurz.',
                        maxMessage: 'Die Nachricht ist zu lang.'
                    ),
                ],
            ]);

            $violations = $validator->validate($data, $inputBag);

            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[$violation->getPropertyPath()] = $violation->getMessage();
                }
                $this->logger->warning('Contact form validation failed: ' . json_encode($errors));
                return new JsonResponse(['message' => 'Validierungsfehler', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
            }

            $name = trim($data['name']);
            $email = trim($data['email']);
            $subject = trim($data['subject']);
            $messageContent = trim($data['message']);

            // XSS Protection
            $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            $subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
            $messageContent = htmlspecialchars($messageContent, ENT_QUOTES, 'UTF-8');

            $emailMessage = (new Email())
                ->from($this->fromEmail)
                ->replyTo($email)
                ->to($this->toEmail)
                ->subject('Kontaktformular: ' . $subject)
                ->html(
                    '<p><strong>Name:</strong> ' . $name . '</p>' .
                    '<p><strong>E-Mail:</strong> ' . $email . '</p>' .
                    '<p><strong>Betreff:</strong> ' . $subject . '</p>' .
                    '<p><strong>Nachricht:</strong><br>' . nl2br($messageContent) . '</p>'
                );

            $mailer->send($emailMessage);

            $this->logger->info('Kontaktformular erfolgreich gesendet von ' . $email);

            return new JsonResponse(['message' => 'Ihre Nachricht wurde erfolgreich gesendet.'], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Senden der Kontaktformular-E-Mail: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['message' => 'Beim Senden der Nachricht ist ein Fehler aufgetreten.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
