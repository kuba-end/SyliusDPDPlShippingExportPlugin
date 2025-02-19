<?php

/*
 * This file was created by developers working at BitBag
 * Do you need more information about us and what we do? Visit our https://bitbag.io website!
 * We are hiring developers from all over the world. Join us and start your new, exciting adventure and become part of us: https://bitbag.io/career
*/

declare(strict_types=1);

namespace BitBag\DpdPlShippingExportPlugin\EventListener;

use BitBag\DpdPlShippingExportPlugin\Api\WebClientInterface;
use BitBag\SyliusShippingExportPlugin\Entity\ShippingExportInterface;
use Doctrine\Persistence\ObjectManager;
use DPD\Services\DPDService;
use http\Exception\InvalidArgumentException;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Webmozart\Assert\Assert;

final class ShippingExportEventListener
{
    public const DPD_GATEWAY_CODE = 'dpd_pl';

    public const BASE_LABEL_EXTENSION = 'pdf';

    /** @var WebClientInterface */
    private $webClient;

    /** @var FlashBagInterface */
    private $flashBag;

    /** @var Filesystem */
    private $fileSystem;

    /** @var ObjectManager */
    private $shippingExportManager;

    /** @var string */
    private $shippingLabelsPath;

    public function __construct(
        WebClientInterface $webClient,
        FlashBagInterface $flashBag,
        FileSystem $fileSystem,
        ObjectManager $shippingExportManager,
        string $shippingLabelsPath
    ) {
        $this->webClient = $webClient;
        $this->flashBag = $flashBag;
        $this->fileSystem = $fileSystem;
        $this->shippingExportManager = $shippingExportManager;
        $this->shippingLabelsPath = $shippingLabelsPath;
    }

    public function exportShipment(ResourceControllerEvent $exportShipmentEvent): void
    {
        /** @var ShippingExportInterface|mixed $shippingExport */
        $shippingExport = $exportShipmentEvent->getSubject();
        Assert::isInstanceOf($shippingExport, ShippingExportInterface::class);

        $shippingGateway = $shippingExport->getShippingGateway();
        Assert::notNull($shippingGateway);

        if ($shippingGateway->getCode() !== self::DPD_GATEWAY_CODE) {
            return;
        }

        $shipment = $shippingExport->getShipment();

        $this->webClient->setShippingGateway($shippingGateway);

        Assert::notNull($shipment);

        $this->webClient->setShipment($shipment);

        try {
            $dpd = new DPDService(
                $shippingGateway->getConfigValue('id'),
                $shippingGateway->getConfigValue('login'),
                $shippingGateway->getConfigValue('password'),
                $shippingGateway->getConfigValue('wsdl')
            );

            $dpd->setSender($this->webClient->getSender());

            $result = $dpd->sendPackage($this->webClient->getParcels(), $this->webClient->getReceiver(), 'SENDER', $this->webClient->getServices());

            $speedLabel = $dpd->generateSpeedLabelsByPackageIds([$result->packageId], $this->webClient->getPickupAddress());    /** @phpstan-ignore-line */
        } catch (\Exception $exception) {
            $this->flashBag->add('error', sprintf(
                'DPD Web Service for #%s order: %s',
                $shipment->getOrder() !== null ? (string) $shipment->getOrder()->getNumber() : '',
                $exception->getMessage()
            ));

            return;
        }

        $this->flashBag->add('success', 'bitbag.ui.shipment_data_has_been_exported');
        $this->saveShippingLabel($shippingExport, $speedLabel->filedata, self::BASE_LABEL_EXTENSION);   /** @phpstan-ignore-line */
        $this->markShipmentAsExported($shippingExport);
    }

    public function saveShippingLabel(
        ShippingExportInterface $shippingExport,
        string $labelContent,
        string $labelExtension
    ): void {
        $labelPath = $this->shippingLabelsPath
            . '/' . $this->getFilename($shippingExport)
            . '.' . $labelExtension;

        $this->fileSystem->dumpFile($labelPath, $labelContent);
        $shippingExport->setLabelPath($labelPath);

        $this->shippingExportManager->persist($shippingExport);
        $this->shippingExportManager->flush();
    }

    private function getFilename(ShippingExportInterface $shippingExport): string
    {
        $shipment = $shippingExport->getShipment();
        Assert::notNull($shipment);

        $order = $shipment->getOrder();
        Assert::notNull($order);

        $orderNumber = $order->getNumber();

        $shipmentId = $shipment->getId();

        return implode(
            '_',
            [
                $shipmentId,
                preg_replace('~[^A-Za-z0-9]~', '', (string) $orderNumber),
            ]
        );
    }

    private function markShipmentAsExported(ShippingExportInterface $shippingExport): void
    {
        $shippingExport->setState(ShippingExportInterface::STATE_EXPORTED);
        $shippingExport->setExportedAt(new \DateTime());

        $this->shippingExportManager->persist($shippingExport);
        $this->shippingExportManager->flush();
    }
}
