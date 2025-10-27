<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/health")
 */
class HealthController extends Controller
{
    /**
     * @Route("", name="app_health")
     * @Method("GET")
     */
    public function healthAction()
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
