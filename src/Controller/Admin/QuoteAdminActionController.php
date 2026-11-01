<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Admin;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverterInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\ConvertQuoteToOrderType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;

final class QuoteAdminActionController extends AbstractController
{
    use AdminQuoteHelperTrait;

    /**
     * @param ShippingMethodRepositoryInterface<ShippingMethodInterface> $shippingMethodRepository
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param FactoryInterface<AddressInterface> $addressFactory
     */
    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestFactoryInterface $quoteRequestFactory,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteOrderConverterInterface $converter,
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly FactoryInterface $addressFactory,
        private readonly TranslatorInterface $translator,
        private readonly QuotePdfGeneratorInterface $pdfGenerator,
    ) {
    }

    public function transitionAction(Request $request, int $id, string $transition): Response
    {
        $quoteRequest = $this->find($id);
        if (!in_array($transition, QuoteProposalController::TRANSITIONS, true)) {
            throw new NotFoundHttpException();
        }
        if (!$this->isCsrfTokenValid(sprintf('tmonier_quote_request_admin_%s_%d', $transition, $id), (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $this->quoteRequestManager->apply($quoteRequest, $transition);
            $this->addFlash('success', 'tmonier_quote_request.flash.admin.' . $transition);
        } catch (QuoteTransitionException $exception) {
            $this->addFlash('error', $exception->isConcurrentChange() ? 'tmonier_quote_request.flash.quote_changed' : 'tmonier_quote_request.flash.transition_not_allowed');

            return $this->redirectToRoute('tmonier_quote_request_admin_quote_request_show', ['id' => $id]);
        }

        return $this->redirectToQuote($quoteRequest);
    }

    public function messageAction(Request $request, int $id): Response
    {
        $quoteRequest = $this->find($id);
        $form = $this->createForm(QuoteMessageType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{body: string} $data */
            $data = $form->getData();
            $this->quoteRequestManager->postMessage($quoteRequest, $this->adminMessage(trim($data['body'])));
            $this->addFlash('success', 'tmonier_quote_request.flash.message_sent');
        } else {
            $this->addFlash('error', 'tmonier_quote_request.flash.message_invalid');
        }

        return $this->redirectToQuote($quoteRequest);
    }

    public function convertAction(Request $request, int $id): Response
    {
        $quoteRequest = $this->find($id);
        if (!$this->converter->canConvert($quoteRequest)) {
            $this->addFlash('error', 'tmonier_quote_request.flash.cannot_convert');

            return $this->redirectToQuote($quoteRequest);
        }

        /** @var ChannelInterface $channel */
        $channel = $quoteRequest->getChannel();
        $address = $this->defaultAddress($quoteRequest);

        $form = $this->createForm(ConvertQuoteToOrderType::class, ['address' => $address], [
            'shipping_methods' => array_values($this->shippingMethodRepository->findEnabledForChannel($channel)),
            'payment_methods' => array_values($this->paymentMethodRepository->findEnabledForChannel($channel)),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{address: AddressInterface, shippingMethod: ShippingMethodInterface|null, paymentMethod: PaymentMethodInterface} $data */
            $data = $form->getData();

            try {
                $order = $this->converter->convert($quoteRequest, $data['address'], $data['shippingMethod'], $data['paymentMethod']);
            } catch (QuoteTransitionException|\RuntimeException $exception) {
                $form->addError(new FormError($this->translator->trans('tmonier_quote_request.flash.conversion_failed', ['%reason%' => $exception->getMessage()], 'flashes')));

                return $this->renderConvert($quoteRequest, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('success', 'tmonier_quote_request.flash.admin.converted');

            return $this->redirectToRoute('sylius_admin_order_show', ['id' => $order->getId()]);
        }

        return $this->renderConvert($quoteRequest, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function convertNowAction(Request $request, int $id): RedirectResponse
    {
        $quoteRequest = $this->find($id);
        if (!$this->isCsrfTokenValid(sprintf('tmonier_quote_request_admin_convert_now_%d', $id), (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $address = $quoteRequest->getCustomer()?->getDefaultAddress();
        if (!$this->converter->canConvert($quoteRequest) || !$address instanceof \Sylius\Component\Core\Model\AddressInterface) {
            $this->addFlash('error', $address instanceof \Sylius\Component\Core\Model\AddressInterface ? 'tmonier_quote_request.flash.cannot_convert' : 'tmonier_quote_request.flash.no_default_address');

            return $this->redirectToRoute('tmonier_quote_request_admin_quote_request_convert', ['id' => $id]);
        }

        try {
            $order = $this->converter->convert($quoteRequest, $address, null, null);
        } catch (QuoteTransitionException|\RuntimeException $exception) {
            $this->addFlash('error', ['message' => 'tmonier_quote_request.flash.conversion_failed', 'parameters' => ['%reason%' => $exception->getMessage()]]);

            return $this->redirectToRoute('tmonier_quote_request_admin_quote_request_convert', ['id' => $id]);
        }

        $this->addFlash('success', 'tmonier_quote_request.flash.admin.converted');

        return $this->redirectToRoute('sylius_admin_order_show', ['id' => $order->getId()]);
    }

    public function pdfAction(int $id): Response
    {
        $quoteRequest = $this->find($id);
        if (!$this->pdfGenerator->isAvailable() || $quoteRequest->getLines()->isEmpty()) {
            throw new NotFoundHttpException('No PDF for this quote.');
        }

        return new Response($this->pdfGenerator->generate($quoteRequest), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->pdfGenerator->getFilename($quoteRequest)),
        ]);
    }

    private function defaultAddress(QuoteRequestInterface $quoteRequest): AddressInterface
    {
        $customerAddress = $quoteRequest->getCustomer()?->getDefaultAddress();
        if ($customerAddress instanceof \Sylius\Component\Core\Model\AddressInterface) {
            $address = clone $customerAddress;
            $address->setCustomer(null);

            return $address;
        }

        $address = $this->addressFactory->createNew();
        $address->setFirstName($quoteRequest->getFirstName());
        $address->setLastName($quoteRequest->getLastName());
        $address->setCompany($quoteRequest->getCompany());
        $address->setPhoneNumber($quoteRequest->getPhoneNumber());

        return $address;
    }

    private function renderConvert(QuoteRequestInterface $quoteRequest, FormInterface $form, int $status): Response
    {
        return $this->render('@TmonierSyliusQuoteRequestPlugin/admin/quote_request/convert.html.twig', [
            'quote_request' => $quoteRequest,
            'resource' => $quoteRequest,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }
}
