<?php
declare(strict_types=1);

namespace Custom\Emailpdf\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Framework\Mail\EmailMessageInterfaceFactory;
use Magento\Framework\Mail\MimeMessageInterfaceFactory;
use Magento\Framework\Mail\MimePartInterfaceFactory;
use Magento\Framework\Mail\AddressFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Mail\MimeInterface;

class SendOrderPdf implements ObserverInterface
{
    // =========================================================
    // TEST / DEBUG CONTROLS
    // =========================================================
    private const TEST_MODE          = false;
    private const TEST_ORDER_ID      = '000000002';         // increment_id of an existing order
    private const OVERRIDE_RECIPIENT = ''; // empty = use store General Contact email
    // =========================================================

    private ScopeConfigInterface         $scopeConfig;
    private TransportInterfaceFactory    $transportFactory;
    private EmailMessageInterfaceFactory $emailMessageFactory;
    private MimeMessageInterfaceFactory  $mimeMessageFactory;
    private MimePartInterfaceFactory     $mimePartFactory;
    private AddressFactory               $addressFactory;
    private LoggerInterface              $logger;
    private OrderRepositoryInterface     $orderRepository;

    public function __construct(
        ScopeConfigInterface         $scopeConfig,
        TransportInterfaceFactory    $transportFactory,
        EmailMessageInterfaceFactory $emailMessageFactory,
        MimeMessageInterfaceFactory  $mimeMessageFactory,
        MimePartInterfaceFactory     $mimePartFactory,
        AddressFactory               $addressFactory,
        LoggerInterface              $logger,
        OrderRepositoryInterface     $orderRepository
    ) {
        $this->scopeConfig         = $scopeConfig;
        $this->transportFactory    = $transportFactory;
        $this->emailMessageFactory = $emailMessageFactory;
        $this->mimeMessageFactory  = $mimeMessageFactory;
        $this->mimePartFactory     = $mimePartFactory;
        $this->addressFactory      = $addressFactory;
        $this->logger              = $logger;
        $this->orderRepository     = $orderRepository;
    }

    // ----------------------------------------------------------
    // Entry point — called by Magento event system
    // ----------------------------------------------------------
    public function execute(Observer $observer): void
    {
        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getEvent()->getOrder();

            if (self::TEST_MODE) {
                $om             = \Magento\Framework\App\ObjectManager::getInstance();
                $scb            = $om->create(\Magento\Framework\Api\SearchCriteriaBuilder::class)
                                     ->addFilter('increment_id', self::TEST_ORDER_ID)
                                     ->create();
                $results        = $this->orderRepository->getList($scb)->getItems();
                if (empty($results)) {
                    $this->rawLog('WARNING: TEST_MODE order not found: ' . self::TEST_ORDER_ID);
                    return;
                }
                $order = reset($results);
                $this->rawLog('TEST_MODE active — using order #' . self::TEST_ORDER_ID);
            }

            $pdfBytes  = $this->buildPdf($order);
            $recipient = self::OVERRIDE_RECIPIENT ?: $this->getGeneralContactEmail();
            $this->sendEmail($recipient, $pdfBytes, $order->getIncrementId());

            $msg = sprintf('[Custom_Emailpdf] PDF sent for order #%s to %s', $order->getIncrementId(), $recipient);
            $this->rawLog($msg);
            if (PHP_SAPI === 'cli') {
                echo "[SUCCESS] Email dispatched to {$recipient}\n";
            }
        } catch (\Throwable $e) {
            $this->rawLog('ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            if (PHP_SAPI === 'cli') {
                echo "[ERROR] " . $e->getMessage() . "\n";
            }
        }
    }

    // ----------------------------------------------------------
    // Email dispatch — uses Magento's own MIME + transport stack
    // ----------------------------------------------------------
    private function sendEmail(string $recipient, string $pdfBytes, string $incrementId): void
    {
        $storeName   = $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE) ?: 'Store';
        $senderEmail = $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE) ?: $recipient;
        $senderName  = $this->scopeConfig->getValue('trans_email/ident_general/name', ScopeInterface::SCOPE_STORE) ?: $storeName;

        $subject  = sprintf('New Order #%s - %s', $incrementId, $storeName);
        $bodyText = sprintf(
            "Hello,\n\nA new order (#%s) has been placed on %s.\nPlease find the order PDF attached.\n\nRegards,\n%s",
            $incrementId,
            $storeName,
            $storeName
        );

        // Text part
        $textPart = $this->mimePartFactory->create([
            'content'     => $bodyText,
            'type'        => MimeInterface::TYPE_TEXT,
            'disposition' => MimeInterface::DISPOSITION_INLINE,
            'encoding'    => MimeInterface::ENCODING_QUOTED_PRINTABLE,
            'charset'     => 'UTF-8',
        ]);

        // PDF attachment part
        $pdfPart = $this->mimePartFactory->create([
            'content'     => $pdfBytes,
            'type'        => 'application/pdf',
            'disposition' => MimeInterface::DISPOSITION_ATTACHMENT,
            'encoding'    => MimeInterface::ENCODING_BASE64,
            'fileName'    => "order_{$incrementId}.pdf",
        ]);

        // Mime message (Magento wrapper)
        $mimeMessage = $this->mimeMessageFactory->create(['parts' => [$textPart, $pdfPart]]);

        // Magento Address objects
        $toAddress   = $this->addressFactory->create(['email' => $recipient,    'name' => null]);
        $fromAddress = $this->addressFactory->create(['email' => $senderEmail,  'name' => $senderName]);

        // EmailMessage
        $emailMessage = $this->emailMessageFactory->create([
            'body'    => $mimeMessage,
            'subject' => $subject,
            'to'      => [$toAddress],
            'from'    => [$fromAddress],
        ]);

        $transport = $this->transportFactory->create(['message' => $emailMessage]);
        $transport->sendMessage();
    }

    // ----------------------------------------------------------
    // Fetch general contact email from store config
    // ----------------------------------------------------------
    private function getGeneralContactEmail(): string
    {
        $email = $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE);
        if (!$email) {
            throw new \RuntimeException('General Contact email is not configured in Magento.');
        }
        return $email;
    }

    // ----------------------------------------------------------
    // Raw log — always writes to disk, no Magento DI needed
    // ----------------------------------------------------------
    private function rawLog(string $message): void
    {
        $logFile = BP . '/var/log/custom_emailpdf.log';
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }

    // ===========================================================
    //  SELF-CONTAINED PURE-PHP PDF BUILDER
    //  No Composer dependency. Produces a valid PDF 1.4 in memory.
    // ===========================================================
    private function buildPdf(\Magento\Sales\Model\Order $order): string
    {
        $shipping  = $order->getShippingAddress();
        $billing   = $order->getBillingAddress();
        $addr      = $shipping ?: $billing;

        $fullName  = $addr ? trim($addr->getFirstname() . ' ' . $addr->getLastname()) : 'N/A';
        $street    = $addr ? implode(', ', (array)$addr->getStreet()) : 'N/A';
        $city      = $addr ? (string)$addr->getCity() : 'N/A';
        $region    = $addr ? (string)$addr->getRegion() : '';
        $postcode  = $addr ? (string)$addr->getPostcode() : '';
        $country   = $addr ? (string)$addr->getCountryId() : 'N/A';
        $telephone = $addr ? (string)$addr->getTelephone() : 'N/A';
        $cityLine  = trim("{$city}, {$region} {$postcode}");

        $incrementId  = $order->getIncrementId();
        $createdAt    = $order->getCreatedAt();
        $orderDate    = $createdAt ? date('d-M-Y', strtotime((string)$createdAt)) : date('d-M-Y');
        $grandTotal   = number_format((float)$order->getGrandTotal(), 2);
        $currencyCode = $order->getOrderCurrencyCode();
        $paymentTitle = $order->getPayment() ? (string)$order->getPayment()->getMethodInstance()->getTitle() : 'N/A';

        $itemRows = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $itemRows[] = [
                'name'  => (string)$item->getName(),
                'sku'   => (string)$item->getSku(),
                'qty'   => (int)$item->getQtyOrdered(),
                'total' => number_format((float)$item->getRowTotal(), 2),
            ];
        }

        $sep   = str_repeat('=', 58);
        $dash  = str_repeat('-', 58);
        $lines = [];

        $lines[] = $sep;
        $lines[] = self::centerText('ORDER CONFIRMATION', 58);
        $lines[] = self::centerText('Order #' . $incrementId, 58);
        $lines[] = $sep;
        $lines[] = '';
        $lines[] = sprintf("%-18s %s", 'Order Date :', $orderDate);
        $lines[] = sprintf("%-18s %s", 'Payment    :', $paymentTitle);
        $lines[] = '';
        $lines[] = 'SHIP TO';
        $lines[] = $dash;
        $lines[] = sprintf("%-18s %s", 'Full Name  :', $fullName);
        $lines[] = sprintf("%-18s %s", 'Street     :', $street);
        $lines[] = sprintf("%-18s %s", 'City/State :', $cityLine);
        $lines[] = sprintf("%-18s %s", 'Country    :', $country);
        $lines[] = sprintf("%-18s %s", 'Telephone  :', $telephone);
        $lines[] = '';
        $lines[] = 'ITEMS ORDERED';
        $lines[] = $dash;
        $lines[] = sprintf("%-28s %-12s %5s  %10s", 'Product Name', 'SKU', 'Qty', 'Row Total');
        $lines[] = $dash;

        foreach ($itemRows as $row) {
            $lines[] = sprintf(
                "%-28s %-12s %5d  %10s %s",
                mb_strimwidth($row['name'], 0, 27, '...'),
                mb_strimwidth($row['sku'],  0, 11, '...'),
                $row['qty'],
                $row['total'],
                $currencyCode
            );
        }

        $lines[] = $dash;
        $lines[] = sprintf("%48s %s %s", 'Grand Total: ', $grandTotal, $currencyCode);
        $lines[] = '';
        $lines[] = $sep;
        $lines[] = self::centerText('Thank you for your order!', 58);
        $lines[] = $sep;

        return self::buildMinimalPdf(implode("\n", $lines));
    }

    private static function centerText(string $text, int $width): string
    {
        $pad = max(0, intval(($width - mb_strlen($text)) / 2));
        return str_repeat(' ', $pad) . $text;
    }

    // ===========================================================
    //  Minimal pure-PHP PDF 1.4 generator (zero dependencies)
    // ===========================================================
    private static function buildMinimalPdf(string $text): string
    {
        $fontSize  = 9;
        $leading   = 13;
        $marginX   = 40;
        $marginTop = 780;
        $pageW     = 595;
        $pageH     = 842;

        $lines   = explode("\n", $text);
        $objects = [];
        $offsets = [];

        $addObj = function (string $content) use (&$objects): int {
            $objects[] = $content;
            return count($objects);
        };

        $catalogId = $addObj('');
        $pagesId   = $addObj('');

        $stream  = "BT\n/F1 {$fontSize} Tf\n{$marginX} {$marginTop} Td\n{$leading} TL\n";
        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= "({$escaped}) '\n";
        }
        $stream .= "ET\n";

        $contentId = $addObj("<<\n/Length " . strlen($stream) . "\n>>\nstream\n" . $stream . "endstream");
        $pageId    = $addObj(
            "<<\n/Type /Page\n/Parent 2 0 R\n" .
            "/MediaBox [0 0 {$pageW} {$pageH}]\n" .
            "/Contents {$contentId} 0 R\n" .
            "/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Courier >> >> >>\n>>"
        );
        $addObj("<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Courier\n>>");

        $objects[$pagesId   - 1] = "<<\n/Type /Pages\n/Kids [{$pageId} 0 R]\n/Count 1\n>>";
        $objects[$catalogId - 1] = "<<\n/Type /Catalog\n/Pages {$pagesId} 0 R\n>>";

        $pdf  = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        foreach ($objects as $i => $content) {
            $id = $i + 1;
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$content}\nendobj\n";
        }

        $xref  = strlen($pdf);
        $count = count($objects) + 1;
        $pdf  .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<<\n/Size {$count}\n/Root 1 0 R\n>>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
