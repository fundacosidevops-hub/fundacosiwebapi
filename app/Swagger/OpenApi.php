<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "Fundacosi",
    version: "1.0.0"
)]

#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]

#[OA\Server(
    url: "http://localhost:8000",
    description: "Servidor Local"
)]
class OpenApi {}
