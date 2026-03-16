<?php declare(strict_types=1);

namespace NorilivingDruckfreigabe\Storefront\Controller;

use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class DruckfreigabeController extends StorefrontController
{
    #[Route(
        path: '/druckfreigabe/{orderNumber}',
        name: 'frontend.druckfreigabe.page',
        defaults: ['_csrf_protection' => false],
        methods: ['GET']
    )]
    public function index(string $orderNumber, Request $request): Response
    {
        if (!$this->isAccessAllowed($orderNumber, $request)) {
            return $this->renderStorefront(
                '@Storefront/storefront/page/druckfreigabe/index.html.twig',
                [
                    'orderNumber'    => $orderNumber,
                    'showVerifyForm' => true,
                    'verifyError'    => null,
                ]
            );
        }

        $data = $this->loadOrderData($orderNumber);

        if ($data === null) {
            return new Response('Bestellung nicht gefunden: ' . $orderNumber, 404);
        }

        return $this->renderStorefront(
            '@Storefront/storefront/page/druckfreigabe/index.html.twig',
            array_merge($data, [
                'orderNumber'    => $orderNumber,
                'showVerifyForm' => false,
                'success'        => false,
                'error'          => null,
            ])
        );
    }

    #[Route(
        path: '/druckfreigabe/{orderNumber}/verify',
        name: 'frontend.druckfreigabe.verify',
        defaults: ['_csrf_protection' => false],
        methods: ['POST']
    )]
    public function verify(string $orderNumber, Request $request): Response
    {
        $plz     = trim($request->request->get('plz', ''));
        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . '/media/som/' . $orderNumber . '_XML.xml';

        if (!file_exists($xmlPath)) {
            return new Response('Bestellung nicht gefunden: ' . $orderNumber, 404);
        }

        $xml    = simplexml_load_file($xmlPath);
        $xmlPlz = trim((string) $xml->shipping_to->order_shipping_zipcode);

        if ($plz !== $xmlPlz) {
            return $this->renderStorefront(
                '@Storefront/storefront/page/druckfreigabe/index.html.twig',
                [
                    'orderNumber'    => $orderNumber,
                    'showVerifyForm' => true,
                    'verifyError'    => 'Die eingegebene Postleitzahl stimmt nicht überein.',
                ]
            );
        }

        $request->getSession()->set('druckfreigabe_verified_' . $orderNumber, true);

        return $this->redirectToRoute('frontend.druckfreigabe.page', ['orderNumber' => $orderNumber]);
    }

    #[Route(
        path: '/druckfreigabe/{orderNumber}',
        name: 'frontend.druckfreigabe.submit',
        defaults: ['_csrf_protection' => false],
        methods: ['POST']
    )]
    public function submit(string $orderNumber, Request $request): Response
    {
        if (!$this->isAccessAllowed($orderNumber, $request)) {
            return $this->redirectToRoute('frontend.druckfreigabe.page', ['orderNumber' => $orderNumber]);
        }

        $approval = $request->request->get('approval', '');
        $comment  = trim($request->request->get('comment', ''));

        $data = $this->loadOrderData($orderNumber);

        if ($data === null) {
            return new Response('Bestellung nicht gefunden: ' . $orderNumber, 404);
        }

        if (!in_array($approval, ['ja', 'nein'], true)) {
            return $this->renderStorefront(
                '@Storefront/storefront/page/druckfreigabe/index.html.twig',
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
            $posNode = $positionsNode->addChild('position');
            $posNode->addChild('number', $position['number']);

            $posAttrs     = $posNode->addChild('attributes');

            $freigabeAttr = $posAttrs->addChild('attribute');
            $freigabeAttr->addChild('name', 'd_druckfreigabe');
            $freigabeAttr->addChild('value', $druckfreigabeValue);

            $posKomAttr = $posAttrs->addChild('attribute');
            $posKomAttr->addChild('name', 'd_kommentar');
            $posKomAttr->addChild('value', htmlspecialchars($comment, ENT_XML1, 'UTF-8'));
        }

        $outputDir = $_SERVER['DOCUMENT_ROOT'] . '/media/som-druckfreigabe';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = true;
        $dom->loadXML($xml->asXML());
        $dom->save($outputDir . '/druckfreigabe-' . $orderNumber . '.xml');

        return $this->renderStorefront(
            '@Storefront/storefront/page/druckfreigabe/index.html.twig',
            array_merge($data, [
                'orderNumber'    => $orderNumber,
                'showVerifyForm' => false,
                'success'        => true,
                'approvalValue'  => $druckfreigabeValue,
                'error'          => null,
            ])
        );
    }

    private function isAccessAllowed(string $orderNumber, Request $request): bool
    {
        // PLZ per Session verifiziert
        if ($request->getSession()->get('druckfreigabe_verified_' . $orderNumber) === true) {
            return true;
        }

        // Kunde ist eingeloggt
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if ($context !== null && $context->getCustomer() !== null) {
            return true;
        }

        return false;
    }

    private function loadOrderData(string $orderNumber): ?array
    {
        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . '/media/som/' . $orderNumber . '_XML.xml';

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
