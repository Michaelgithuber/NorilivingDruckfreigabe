<?php declare(strict_types=1);

namespace NorilivingDruckfreigabe\Storefront\Controller;

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
        methods: ['GET']
    )]
    public function index(string $orderNumber, Request $request): Response
    {

        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . '/media/som/druckfreigabe-' . $orderNumber . '.xml';

        if (!file_exists($xmlPath)) {
            return new Response('XML nicht gefunden: ' . $xmlPath);
        }

        $xml = simplexml_load_file($xmlPath);

        $positions = [];

        if (isset($xml->positions->position)) {

            foreach ($xml->positions->position as $position) {

                $positionNumber = (string)$position->number;

                $positions[] = [
                    'number' => $positionNumber,

                    'preview' =>
                        'https://druckdaten.heinikel.com/extern/druckpng_neu/' .
                        $orderNumber . '-' . $positionNumber . '-platte-1',

                    'download' =>
                        'https://druckdaten.heinikel.com/extern/' .
                        $orderNumber . '-' . $positionNumber . '-platte-1'
                ];
            }
        }

        return $this->renderStorefront(
            '@Storefront/storefront/page/druckfreigabe/index.html.twig',
            [
                'orderNumber' => $orderNumber,
                'positions' => $positions
            ]
        );
    }

}