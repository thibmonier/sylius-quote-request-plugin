<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Admin;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Conversion\QuoteOrderConverterInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Factory\QuoteRequestFactoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin\QuoteRequestAdminType;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteRequestManagerInterface;
use Tmonier\SyliusQuoteRequestPlugin\Manager\QuoteTransitionException;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestTransitions;

final class QuoteProposalController extends AbstractController
{
    use AdminQuoteHelperTrait;

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
     * @param array<string, float> $customerGroupDiscounts
     */
    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly QuoteRequestFactoryInterface $quoteRequestFactory,
        private readonly QuoteRequestManagerInterface $quoteRequestManager,
        private readonly QuoteOrderConverterInterface $converter,
        private readonly TranslatorInterface $translator,
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

    private function groupDiscount(QuoteRequestInterface $quoteRequest): ?float
    {
        $code = $quoteRequest->getCustomer()?->getGroup()?->getCode();

        return null === $code ? null : ($this->customerGroupDiscounts[$code] ?? null);
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
        if (!$line instanceof \Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface) {
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

        if ($message instanceof QuoteMessageInterface) {
            $this->quoteRequestManager->postMessage($quoteRequest, $message);
        } else {
            $this->quoteRequestManager->save($quoteRequest);
        }
        $this->addFlash('success', 'tmonier_quote_request.flash.admin.saved');

        return $this->redirectToQuote($quoteRequest);
    }
}
