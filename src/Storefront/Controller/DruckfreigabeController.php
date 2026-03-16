<?php declare(strict_types=1);

namespace NorilivingDruckfreigabe\Storefront\Controller;

use Shopware\Core\Content\Cms\SalesChannel\AbstractCmsPageLoader;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class DruckfreigabeController extends StorefrontController
{
    private const CMS_PAGE_ID      = '019cf7977caa78b7b2c148439054ef63';
    private const MAX_PLZ_ATTEMPTS = 5;
    private const PLZ_LOCKOUT_SECS = 900; // 15 Minuten

    public function __construct(
        private readonly AbstractCmsPageLoader $cmsPageLoader
    ) {}

    // ── PLZ-Eingabe Landingpage ──────────────────────────────────────────────

    #[Route(
        path: '/druckfreigabe',
        name: 'frontend.druckfreigabe.landing',
        defaults: ['_csrf_protection' => false],
        methods: ['GET', 'POST']
    )]
    public function landing(Request $request): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $orderNumber = trim($request->request->get('orderNumber', ''));
            $plz         = trim($request->request->get('plz', ''));

            // 1) Path-Traversal-Schutz
            if (!preg_match('/^\d+$/', $orderNumber)) {
                $error = 'Ungültige Bestellnummer.';
            } elseif ($orderNumber === '' || $plz === '') {
                $error = 'Bitte Bestellnummer und Postleitzahl eingeben.';
            } else {
                $verifyResult = $this->verifyPlz($orderNumber, $plz, $request);

                if ($verifyResult === 'locked') {
                    $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
                } elseif ($verifyResult === 'not_found') {
                    $error = 'Bestellung nicht gefunden.';
                } elseif ($verifyResult === 'wrong_plz') {
                    $remaining = self::MAX_PLZ_ATTEMPTS - $this->getPlzAttempts($orderNumber, $request);
                    $error = 'Die eingegebene Postleitzahl stimmt nicht überein. Noch ' . max(0, $remaining) . ' Versuch(e).';
                } else {
                    // Erfolgreich verifiziert
                    return $this->redirectToRoute('frontend.druckfreigabe.page', ['orderNumber' => $orderNumber]);
                }
            }
        }

        return $this->renderStorefront(
            '@NorilivingDruckfreigabe/storefront/page/druckfreigabe/index.html.twig',
            [
                'orderNumber'    => $request->request->get('orderNumber', ''),
                'showVerifyForm' => true,
                'showLanding'    => true,
                'verifyError'    => $error,
            ]
        );
    }

    // ── Druckfreigabe-Seite anzeigen ─────────────────────────────────────────

    #[Route(
        path: '/druckfreigabe/{orderNumber}',
        name: 'frontend.druckfreigabe.page',
        defaults: ['_csrf_protection' => false],
        methods: ['GET']
    )]
    public function index(string $orderNumber, Request $request): Response
    {
        // 1) Path-Traversal-Schutz
        if (!preg_match('/^\d+$/', $orderNumber)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isAccessAllowed($orderNumber, $request)) {
            return $this->renderStorefront(
                '@NorilivingDruckfreigabe/storefront/page/druckfreigabe/index.html.twig',
                [
                    'orderNumber'    => $orderNumber,
                    'showVerifyForm' => true,
                    'verifyError'    => null,
                ]
            );
        }

        $data = $this->loadOrderData($orderNumber);

        if ($data === null) {
            throw $this->createNotFoundException('Bestellung nicht gefunden: ' . $orderNumber);
        }

        $response = $this->renderStorefront(
            '@NorilivingDruckfreigabe/storefront/page/druckfreigabe/index.html.twig',
            array_merge($data, [
                'orderNumber'    => $orderNumber,
                'showVerifyForm' => false,
                'success'        => false,
                'error'          => null,
            ])
        );

        $this->setNoCacheHeaders($response);

        return $response;
    }

    // ── PLZ-Verifikation POST ────────────────────────────────────────────────

    #[Route(
        path: '/druckfreigabe/{orderNumber}/verify',
        name: 'frontend.druckfreigabe.verify',
        defaults: ['_csrf_protection' => false],
        methods: ['POST']
    )]
    public function verify(string $orderNumber, Request $request): Response
    {
        // 1) Path-Traversal-Schutz
        if (!preg_match('/^\d+$/', $orderNumber)) {
            throw $this->createNotFoundException();
        }

        $plz          = trim($request->request->get('plz', ''));
        $verifyResult = $this->verifyPlz($orderNumber, $plz, $request);
        $error        = null;

        if ($verifyResult === 'locked') {
            $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
        } elseif ($verifyResult === 'not_found') {
            throw $this->createNotFoundException('Bestellung nicht gefunden: ' . $orderNumber);
        } elseif ($verifyResult === 'wrong_plz') {
            $remaining = self::MAX_PLZ_ATTEMPTS - $this->getPlzAttempts($orderNumber, $request);
            $error     = 'Die eingegebene Postleitzahl stimmt nicht überein. Noch ' . max(0, $remaining) . ' Versuch(e).';
        } else {
            return $this->redirectToRoute('frontend.druckfreigabe.page', ['orderNumber' => $orderNumber]);
        }

        return $this->renderStorefront(
            '@NorilivingDruckfreigabe/storefront/page/druckfreigabe/index.html.twig',
            [
                'orderNumber'    => $orderNumber,
                'showVerifyForm' => true,
                'verifyError'    => $error,
            ]
        );
    }

    // ── Freigabe abschicken ──────────────────────────────────────────────────

    #[Route(
        path: '/druckfreigabe/{orderNumber}',
        name: 'frontend.druckfreigabe.submit',
        defaults: ['_csrf_protection' => false],
        methods: ['POST']
    )]
    public function submit(string $orderNumber, Request $request): Response
    {
        // 1) Path-Traversal-Schutz
        if (!preg_match('/^\d+$/', $orderNumber)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isAccessAllowed($orderNumber, $request)) {
            return $this->redirectToRoute('frontend.druckfreigabe.page', ['orderNumber' => $orderNumber]);
        }

        $approval = $request->request->get('approval', '');
        $comment  = trim($request->request->get('comment', ''));

        $data = $this->loadOrderData($orderNumber);

        if ($data === null) {
            throw $this->createNotFoundException('Bestellung nicht gefunden: ' . $orderNumber);
        }

        if (!in_array($approval, ['ja', 'nein'], true)) {
            return $this->renderStorefront(
                '@NorilivingDruckfreigabe/storefront/page/druckfreigabe/index.html.twig',
                array_merge($data, [
                    'orderNumber'    => $orderNumber,
                    'showVerifyForm' => false,
                    'success'        => false,
                    'error'          => 'Bitte wählen Sie Ja oder Nein.',
                ])
            );
        }

        $druckfreigabeValue = $approval === 'ja' ? 'erteilt' : 'abgelehnt';
        $timestamp          = (new \DateTime())->format('Y-m-d\TH:i:s');

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><order/>');
        $xml->addChild('number', $orderNumber);

        $attributes = $xml->addChild('attributes');

        $tsAttr = $attributes->addChild('attribute');
        $tsAttr->addChild('name', 'd_zeitstempel');
        $tsAttr->addChild('value', $timestamp);

        $komAttr = $attributes->addChild('attribute');
        $komAttr->addChild('name', 'd_kommentar');
        $komAttr->addChild('value', htmlspecialchars($comment, ENT_XML1, 'UTF-8'));

        $positionsNode = $xml->addChild('positions');

        foreach ($data['positions'] as $position) {
            $posNode  = $positionsNode->addChild('position');
            $posNode->addChild('number', $position['number']);

            $posAttrs     = $posNode->addChild('attributes');

            $freigabeAttr = $posAttrs->addChild('attribute');
            $freigabeAttr->addChild('name', 'd_druckfreigabe');
            $freigabeAttr->addChild('value', $druckfreigabeValue);

            $posKomAttr = $posAttrs->addChild('attribute');
            $posKomAttr->addChild('name', 'd_kommentar');
            $posKomAttr->addChild('value', htmlspecialchars($comment, ENT_XML1, 'UTF-8'));
        }

        $outputDir = $_SERVER['DOCUMENT_ROOT'] . '/media/norilivingdruckfreigabe/som-druckfreigabe';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = true;
        $dom->loadXML($xml->asXML());
        $dom->save($outputDir . '/druckfreigabe-' . $orderNumber . '.xml');

        $response = $this->renderStorefront(
            '@NorilivingDruckfreigabe/storefront/page/druckfreigabe/index.html.twig',
            array_merge($data, [
                'orderNumber'    => $orderNumber,
                'showVerifyForm' => false,
                'success'        => true,
                'approvalValue'  => $druckfreigabeValue,
                'error'          => null,
                'cmsPage'        => $this->loadCmsPage($request),
            ])
        );

        $this->setNoCacheHeaders($response);

        return $response;
    }

    // ── Hilfsmethoden ────────────────────────────────────────────────────────

    private function loadCmsPage(Request $request): ?object
    {
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if ($context === null) {
            return null;
        }

        $criteria = new Criteria([self::CMS_PAGE_ID]);
        $pages    = $this->cmsPageLoader->load($request, $criteria, $context);

        return $pages->first();
    }

    /**
     * Prüft PLZ gegen XML, zählt Fehlversuche.
     * Gibt zurück: 'ok' | 'locked' | 'not_found' | 'wrong_plz'
     */
    private function verifyPlz(string $orderNumber, string $plz, Request $request): string
    {
        $session    = $request->getSession();
        $lockKey    = 'df_lock_' . $orderNumber;
        $attemptsKey = 'df_attempts_' . $orderNumber;

        // Sperre aktiv?
        $lockedUntil = $session->get($lockKey, 0);
        if (time() < $lockedUntil) {
            return 'locked';
        }

        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . '/media/norilivingdruckfreigabe/som/' . $orderNumber . '_XML.xml';

        if (!file_exists($xmlPath)) {
            return 'not_found';
        }

        $xml    = simplexml_load_file($xmlPath);
        $xmlPlz = trim((string) $xml->shipping_to->order_shipping_zipcode);

        if ($plz !== $xmlPlz) {
            $attempts = (int) $session->get($attemptsKey, 0) + 1;
            $session->set($attemptsKey, $attempts);

            if ($attempts >= self::MAX_PLZ_ATTEMPTS) {
                $session->set($lockKey, time() + self::PLZ_LOCKOUT_SECS);
                $session->set($attemptsKey, 0);
                return 'locked';
            }

            return 'wrong_plz';
        }

        // Erfolgreich: Zähler zurücksetzen, Session setzen
        $session->set($attemptsKey, 0);
        $session->set('druckfreigabe_verified_' . $orderNumber, true);

        return 'ok';
    }

    private function getPlzAttempts(string $orderNumber, Request $request): int
    {
        return (int) $request->getSession()->get('df_attempts_' . $orderNumber, 0);
    }

    private function isAccessAllowed(string $orderNumber, Request $request): bool
    {
        if ($request->getSession()->get('druckfreigabe_verified_' . $orderNumber) === true) {
            return true;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if ($context !== null && $context->getCustomer() !== null) {
            return true;
        }

        return false;
    }

    private function setNoCacheHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }

    private function loadOrderData(string $orderNumber): ?array
    {
        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . '/media/norilivingdruckfreigabe/som/' . $orderNumber . '_XML.xml';

        if (!file_exists($xmlPath)) {
            return null;
        }

        $xml      = simplexml_load_file($xmlPath);
        $shipping = $xml->shipping_to;

        $orderInfo = [
            'number'  => $orderNumber,
            'date'    => (string) $xml->{'r-date'},
            'name'    => trim((string) $shipping->order_firstname . ' ' . (string) $shipping->order_lastname),
            'address' => trim(
                (string) $shipping->order_shipping_street . ', ' .
                (string) $shipping->order_shipping_zipcode . ' ' .
                (string) $shipping->order_shipping_city
            ),
        ];

        $positions = [];

        if (isset($xml->items->item)) {
            foreach ($xml->items->item as $item) {
                $posNumber = (string) $item['pos'];

                $plates = [];
                for ($i = 1; $i <= 5; $i++) {
                    $plateKey = 'druckdatenplatte' . $i;
                    $value    = trim((string) $item->$plateKey);
                    if ($value === '') {
                        continue;
                    }
                    $breiteKey = 'breite_platte' . $i;
                    $plates[] = [
                        'number'   => $i,
                        'name'     => $value,
                        'breite'   => trim((string) $item->$breiteKey),
                        'hoehe'    => trim((string) $item->gesamthoehe),
                        'preview'  => 'https://druckdaten.heinikel.com/extern/druckpng_neu/' . $value,
                        'download' => 'https://druckdaten.heinikel.com/extern/' . $value,
                    ];
                }

                if (empty($plates)) {
                    continue;
                }

                $totalBreite = array_sum(array_map(fn($p) => (int) $p['breite'], $plates));

                $positions[] = [
                    'number'       => $posNumber,
                    'label'        => $orderNumber . '-' . $posNumber,
                    'menge'        => (string) $item->Gesamtmenge,
                    'material'     => (string) $item->material,
                    'farbigkeit'   => (string) $item->farbigkeit,
                    'schneiden'    => (string) $item->schneiden,
                    'veredelung'   => (string) $item->veredelung,
                    'total_breite' => $totalBreite ?: 1000,
                    'plates'       => $plates,
                ];
            }
        }

        return [
            'orderInfo' => $orderInfo,
            'positions' => $positions,
        ];
    }
}
