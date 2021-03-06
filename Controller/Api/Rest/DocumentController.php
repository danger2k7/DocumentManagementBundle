<?php

namespace IDCI\Bundle\DocumentManagementBundle\Controller\Api\Rest;

use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\RequestParam;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcher;
use IDCI\Bundle\DocumentManagementBundle\Form\ApiDocumentType;
use IDCI\Bundle\DocumentManagementBundle\Model\Document;
use IDCI\Bundle\DocumentManagementBundle\Model\Template;
use JMS\Serializer\SerializationContext;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * DocumentController.
 *
 * @Route(name="api_documents_")
 */
class DocumentController extends FOSRestController
{
    /**
     * [GET] /api/documents
     * Retrieve a set of documents.
     *
     * @QueryParam(name="reference", nullable=true, description="(optional) Reference")
     * @QueryParam(name="name", nullable=true, description="(optional) Name")
     * @QueryParam(name="template", nullable=true, description="(optional) Template")
     * @QueryParam(name="limit", nullable=true, description="(optional) Limit", default="100")
     * @QueryParam(name="page", nullable=true, description="(optional) Page", default="0")
     *
     * @param string reference
     *
     * @return Response
     */
    public function getDocumentsAction(ParamFetcher $paramFetcher)
    {
        $em = $this->getDoctrine()->getManager();
        $criteria = [];
        foreach (['reference', 'name', 'template'] as $field) {
            if (null === $paramFetcher->get($field)) {
                continue;
            }

            if ('template' === $field) {
                $template = $em->getRepository(Template::class)->findOneBy([
                    'slug' => $paramFetcher->get($field)
                ]);

                if (null !== $template) {
                    $criteria[$field] = $template;
                }
            } else {
                $criteria[$field] = $paramFetcher->get($field);
            }
        }

        $limit = (int) $paramFetcher->get('limit');
        $offset = (int) $limit * $paramFetcher->get('page');

        $view = $this->view(
            $em->getRepository(Document::class)->findBy($criteria, null, $limit, $offset),
            Response::HTTP_OK
        );

        $context = SerializationContext::create()->setGroups(array('document'));
        $view->setSerializationContext($context);

        return $this->handleView($view);
    }

    /**
     * [GET] /api/documents/{uuid}
     * Retrieve a document.
     *
     * @param string $uuid
     *
     * @return Response
     */
    public function getDocumentAction($uuid)
    {
        try {
            $view = $this->view(
                $this->getDoctrine()->getManager()->getRepository(Document::class)->find($uuid),
                Response::HTTP_OK
            );

            $context = SerializationContext::create()->setGroups(array('document'));
            $view->setSerializationContext($context);

            return $this->handleView($view);
        } catch (\Exception $e) {
            return $this->handleView($this->view(
                array(),
                Response::HTTP_NOT_FOUND
            ));
        }
    }

    /**
     * [POST] /api/documents
     * Add a document.
     *
     * @RequestParam(name="name", strict=true, nullable=false)
     * @RequestParam(name="description", strict=true, nullable=true)
     * @RequestParam(name="data", strict=true, nullable=true)
     * @RequestParam(name="format", strict=true, nullable=true)
     * @RequestParam(name="reference", strict=true, nullable=false)
     * @RequestParam(name="template", strict=true, nullable=false)
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return Response
     */
    public function postDocumentAction(ParamFetcher $paramFetcher)
    {
        $manager = $this->getDoctrine()->getManager();
        $document = new Document();
        $form = $this->createForm(ApiDocumentType::class, $document);
        $view = $this->view();

        try {
            $form->submit($paramFetcher->all());
            if ($form->isSubmitted() && $form->isValid()) {
                $manager->persist($document);
                $manager->flush();

                $view
                    ->setHeader(
                        'Location',
                        $this->generateUrl('api_documents_get_document', array('uuid' => $document->getId())),
                        UrlGeneratorInterface::ABSOLUTE_URL
                    )
                    ->setData(array(
                        'id' => $document->getId(),
                    ))
                    ->setStatusCode(Response::HTTP_CREATED)
                ;
            } else {
                $error = '';
                foreach ($form->getErrors(true) as $formError) {
                    $error .= sprintf(
                        "%s %s: '%s'\n",
                        $formError->getMessage(),
                        $formError->getOrigin()->getName(),
                        $formError->getOrigin()->getData()
                    );
                }

                $view
                    ->setData(array(
                        'error' => $error
                    ))
                    ->setStatusCode(Response::HTTP_BAD_REQUEST)
                ;
            }
        } catch (\Exception $e) {
            $view
                ->setData(array(
                    'error' => $e->getMessage(),
                ))
                ->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR)
            ;
        }

        return $this->handleView($view);
    }

    /**
     * [PATCH] /api/documents/{uuid}
     * Add a document.
     *
     * @RequestParam(name="name", strict=true, nullable=true)
     * @RequestParam(name="description", strict=true, nullable=true)
     * @RequestParam(name="data", strict=true, nullable=true)
     * @RequestParam(name="format", strict=true, nullable=true)
     * @RequestParam(name="reference", strict=true, nullable=true)
     * @RequestParam(name="template", strict=true, nullable=true)
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return Response
     */
    public function patchDocumentAction($uuid, ParamFetcher $paramFetcher)
    {
        $manager = $this->getDoctrine()->getManager();
        $document = $manager->getRepository(Document::class)->find($uuid);
        $view = $this->view();

        if (!$document) {
            $view->setStatusCode(Response::HTTP_NOT_FOUND);

            return $this->handleView($view);
        }

        $form = $this->createForm(ApiDocumentType::class, $document);

        // Remove null values
        $parameters = array_replace($document->toArray(), array_filter($paramFetcher->all()));

        $form->submit($parameters);
        if ($form->isValid()) {
            $manager->flush();

            $view->setStatusCode(Response::HTTP_NO_CONTENT);

            return $this->handleView($view);
        }

        $view->setStatusCode(Response::HTTP_BAD_REQUEST);

        return $this->handleView($view);
    }

    /**
     * [DELETE] /api/documents/{uuid}
     * Delete a document.
     *
     * @param string $uuid
     *
     * @return Response
     */
    public function deleteDocumentAction($uuid)
    {
        $manager = $this->getDoctrine()->getManager();
        $document = $manager->getRepository(Document::class)->find($uuid);
        $view = $this->view();

        if (!$document) {
            $view->setStatusCode(Response::HTTP_NOT_FOUND);

            return $this->handleView($view);
        }

        $manager->remove($document);
        $manager->flush();

        $view->setStatusCode(Response::HTTP_NO_CONTENT);

        return $this->handleView($view);
    }
}
