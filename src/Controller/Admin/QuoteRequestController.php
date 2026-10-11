<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Admin;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverterInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\ConvertQuoteToOrderType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\QuoteRequestAdminType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteRequestController extends AbstractController
{
    /** Transitions the merchant applies with a button on the quote page. */
    public const TRANSITIONS = [
        QuoteRequestTransitions::TRANSITION_REVIEW,
        QuoteRequestTransitions::TRANSITION_PROPOSE,
        QuoteRequestTransitions::TRANSITION_REVISE,
        QuoteRequestTransitions::TRANSITION_DECLINE,
        QuoteRequestTransitions::TRANSITION_EXPIRE,
        QuoteRequestTransitions::TRANSITION_CANCEL,
    ];

    /**
     * @param ShippingMethodRepositoryInterface<ShippingMethodInterface> $shippingMethodRepository
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param FactoryInterface<AddressInterface> $addressFactory
     * @param array<string, float> $customerGroupDiscounts
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
        private readonly int $maxLines,
        private readonly array $customerGroupDiscounts = [],
    ) {
    }

    public function showAction(Request $request, int $id): Response
    {
        $quoteRequest = $this->find($id);
        $editable = in_array($quoteRequest->getStatus(), QuoteRequestStatuses::EDITABLE, true);

        $form = $this->createForm(QuoteRequestAdminType::class, $quoteRequest, [
            'currency' => (string) $quoteRequest->getCurrencyCode(),
            'group_discount' => $editable ? $this->groupDiscount($quoteRequest) : null,
            'disabled' => !$editable,
            'action' => $this->generateUrl('tmonier_quote_request_admin_quote_request_show', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($editable && $form->isSubmitted()) {
            $this->applyLineChanges($form, $quoteRequest);
            if ($form->isValid()) {
                return $this->saveProposal($form, $quoteRequest);
            }
        }

        return $this->render('@TmonierSyliusQuoteRequestPlugin/admin/quote_request/show.html.twig', [
            'quote_request' => $quoteRequest,
            'resource' => $quoteRequest,
            'form' => $form->createView(),
            'editable' => $editable,
            'message_form' => $this->createForm(QuoteMessageType::class, null, [
                'action' => $this->generateUrl('tmonier_quote_request_admin_quote_request_message', ['id' => $id]),
            ])->createView(),
            'transitions' => self::TRANSITIONS,
            'can_convert' => $this->converter->canConvert($quoteRequest),
            'group_discount' => $this->groupDiscount($quoteRequest),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    public function transitionAction(Request $request, int $id, string $transition): Response
    {
        $quoteRequest = $this->find($id);
        if (!in_array($transition, self::TRANSITIONS, true)) {
            throw new NotFoundHttpException();
        }
        if (!$this->isCsrfTokenValid(sprintf('tmonier_quote_request_admin_%s_%d', $transition, $id), (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $this->quoteRequestManager->apply($quoteRequest, $transition);
            $this->addFlash('success', 'tmonier_quote_request.flash.admin.' . $transition);
        } catch (QuoteTransitionException) {
            $this->addFlash('error', 'tmonier_quote_request.flash.transition_not_allowed');
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

    /** Places the order at once with the customer's default address and the default methods. */
    public function convertNowAction(Request $request, int $id): Response
    {
        $quoteRequest = $this->find($id);
        if (!$this->isCsrfTokenValid(sprintf('tmonier_quote_request_admin_convert_now_%d', $id), (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $address = $quoteRequest->getCustomer()?->getDefaultAddress();
        if (!$this->converter->canConvert($quoteRequest) || null === $address) {
            $this->addFlash('error', null === $address ? 'tmonier_quote_request.flash.no_default_address' : 'tmonier_quote_request.flash.cannot_convert');

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

    private function groupDiscount(QuoteRequestInterface $quoteRequest): ?float
    {
        $code = $quoteRequest->getCustomer()?->getGroup()?->getCode();

        return null === $code ? null : ($this->customerGroupDiscounts[$code] ?? null);
    }

    private function renderConvert(QuoteRequestInterface $quoteRequest, FormInterface $form, int $status): Response
    {
        return $this->render('@TmonierSyliusQuoteRequestPlugin/admin/quote_request/convert.html.twig', [
            'quote_request' => $quoteRequest,
            'resource' => $quoteRequest,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }

    private function applyLineChanges(FormInterface $form, QuoteRequestInterface $quoteRequest): void
    {
        foreach ($form->get('lines') as $lineForm) {
            if (true === $lineForm->get('remove')->getData()) {
                /** @var \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface $line */
                $line = $lineForm->getData();
                $quoteRequest->removeLine($line);
            }
        }

        $variant = $form->get('newVariant')->getData();
        if (!$variant instanceof ProductVariantInterface) {
            return;
        }

        /** @var int|null $quantity */
        $quantity = $form->get('newQuantity')->getData();
        $quantity = max(1, $quantity ?? 1);

        foreach ($quoteRequest->getLines() as $line) {
            if ($line->getVariant() === $variant) {
                $line->setQuantity($line->getQuantity() + $quantity);

                return;
            }
        }

        if ($quoteRequest->getLines()->count() >= $this->maxLines) {
            $form->get('newVariant')->addError(new FormError($this->translator->trans('tmonier_quote_request.flash.too_many_lines', [], 'flashes')));

            return;
        }

        $line = $this->quoteRequestFactory->createLine($quoteRequest, $variant, $quantity);
        if (null === $line) {
            $form->get('newVariant')->addError(new FormError($this->translator->trans('tmonier_quote_request.flash.variant_not_in_channel', [], 'flashes')));

            return;
        }
        $line->setRequestedQuantity(null);
        $quoteRequest->addLine($line);
    }

    private function saveProposal(FormInterface $form, QuoteRequestInterface $quoteRequest): Response
    {
        /** @var string|null $body */
        $body = $form->get('message')->getData();
        $message = null !== $body && '' !== trim($body) ? $this->adminMessage(trim($body)) : null;

        if ($form->has('applyGroupDiscount')) {
            $apply = $form->get('applyGroupDiscount');
            if ($apply instanceof SubmitButton && $apply->isClicked()) {
                $quoteRequest->setDiscountAmount(null);
                $quoteRequest->setDiscountPercentage($this->groupDiscount($quoteRequest));
                $this->quoteRequestManager->save($quoteRequest);
                $this->addFlash('success', 'tmonier_quote_request.flash.admin.saved');

                return $this->redirectToQuote($quoteRequest);
            }
        }

        $propose = $form->get('propose');
        if ($propose instanceof SubmitButton && $propose->isClicked()) {
            try {
                $this->quoteRequestManager->apply($quoteRequest, QuoteRequestTransitions::TRANSITION_PROPOSE, $message);
                $this->addFlash('success', 'tmonier_quote_request.flash.admin.propose');
            } catch (QuoteTransitionException) {
                $this->quoteRequestManager->save($quoteRequest);
                $this->addFlash('error', 'tmonier_quote_request.flash.cannot_propose');
            }

            return $this->redirectToQuote($quoteRequest);
        }

        if (null !== $message) {
            $this->quoteRequestManager->postMessage($quoteRequest, $message);
        } else {
            $this->quoteRequestManager->save($quoteRequest);
        }
        $this->addFlash('success', 'tmonier_quote_request.flash.admin.saved');

        return $this->redirectToQuote($quoteRequest);
    }

    private function adminMessage(string $body): QuoteMessageInterface
    {
        $user = $this->getUser();
        $name = $user instanceof AdminUserInterface ? trim(sprintf('%s %s', (string) $user->getFirstName(), (string) $user->getLastName())) : '';
        if ('' === $name && $user instanceof AdminUserInterface) {
            $name = (string) $user->getUsername();
        }

        return $this->quoteRequestFactory->createMessage(QuoteMessageInterface::AUTHOR_ADMIN, '' === $name ? null : $name, $body);
    }

    private function defaultAddress(QuoteRequestInterface $quoteRequest): AddressInterface
    {
        $customerAddress = $quoteRequest->getCustomer()?->getDefaultAddress();
        if (null !== $customerAddress) {
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

    private function redirectToQuote(QuoteRequestInterface $quoteRequest): Response
    {
        return $this->redirectToRoute('tmonier_quote_request_admin_quote_request_show', ['id' => $quoteRequest->getId()]);
    }

    private function find(int $id): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestRepository->find($id);
        if (!$quoteRequest instanceof QuoteRequestInterface) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        return $quoteRequest;
    }
}
