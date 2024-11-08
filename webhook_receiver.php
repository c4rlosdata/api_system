<?php
// webhook_receiver.php
ini_set('max_execution_time', 300); // Aumenta para 300 segundos (5 minutos)

header('Content-Type: application/json');

// Configurações de banco de dados
$host = 'mysql.railway.internal';
$port = '3306';
$user = 'root';
$password = 'zJoulsdBIXJDaSpvJEuNVJinTmIRijjh';
$dbname = 'railway';

$conn = new mysqli($host, $user, $password, $dbname, $port);

// Verificar se a conexão foi bem-sucedida
if ($conn->connect_error) {
    die("Falha na conexão com o banco de dados: " . $conn->connect_error);
}

function obterLock($conn) {
    $result = $conn->query("UPDATE locks SET locked = 1 WHERE id = 1 AND locked = 0");
    if ($result === false) {
        error_log("Erro na consulta de obterLock: " . $conn->error);
        return false;
    }
    if ($conn->affected_rows > 0) {
        error_log("Bloqueio obtido com sucesso.");
        return true;
    } else {
        error_log("Falha ao obter o bloqueio. Nenhuma linha afetada.");
        return false;
    }
}

function liberarLock($conn) {
    $result = $conn->query("UPDATE locks SET locked = 0 WHERE id = 1");
    if ($result) {
        error_log("Lock liberado com sucesso.");
    } else {
        error_log("Erro ao liberar o lock: " . $conn->error);
    }
}

function fazerRequisicaoApiMercos($conn, $clienteId) {
    try {
        // Requisição para a API do MERCOS
        $ch = curl_init();
        $url = "https://app.mercos.com/api/v1/clientes/$clienteId";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "ApplicationToken: $applicationToken",
            "CompanyToken: $companyToken"
        ));

        $response = curl_exec($ch);
        curl_close($ch);

        // Processar a resposta
        if ($response === false) {
            error_log("Erro ao fazer a requisição para o MERCOS");
            return false;
        }

        $data = json_decode($response, true);
        error_log("Resposta do MERCOS: " . print_r($data, true));
        return $data;
    } catch (Exception $e) {
        error_log("Erro: " . $e->getMessage());
    }
}


function processarWebhook($conn) {
    // Receber o payload do webhook
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);

    if (!isset($data['evento'])) {
        error_log("Erro: Evento não encontrado no payload.");
        return;
    }

    if (obterLock($conn)) {  // Obter o lock antes de processar
        try {
            switch ($data['evento']) {
                case 'pedido.gerado':
                    if (processarPedido($conn, $data)) {
                        error_log("Pedido processado com sucesso.");
                    } else {
                        error_log("Erro ao processar o pedido.");
                    }
                    break;
                default:
                    error_log("Evento desconhecido: " . $data['evento']);
                    break;
            }
        } finally {
            liberarLock($conn);  // Liberar o lock após processar
        }
    } else {
        error_log("Falha ao obter o bloqueio. Requisição abortada.");
        exit("Falha ao obter o bloqueio. Tente novamente mais tarde.");
    }
}

function processarPedido($conn, $data) {
    // Obter o CNPJ e Razão Social do cliente
    $clienteRazaoSocial = $data['dados']['cliente_razao_social'];
    $clienteCpfCnpj = preg_replace('/\D/', '', $data['dados']['cpf_cnpj']);  // Somente números no CNPJ

    error_log("Razão social do cliente: " . $clienteRazaoSocial);
    error_log("CPF/CNPJ do cliente: " . $clienteCpfCnpj);

    // Busca apenas pelo CNPJ no banco de dados
    $codContato = buscarCodigoContatoPorCnpj($clienteCpfCnpj);

    // Se não encontrar o código de contato, ou se o CNPJ não estiver correto, insere o cliente
    if (!$codContato) {
        error_log("Código de contato não encontrado ou CPF/CNPJ diferente. Inserindo cliente no eGESTOR...");

        if (isset($data['dados']['cliente_id'])) {
            $clienteId = $data['dados']['cliente_id'];
            $clienteData = buscarClienteMercos($clienteId);

            if ($clienteData) {
                // Corrige o nome fantasia antes de inserir no eGESTOR
                $nomeFantasia = $clienteData['nome_fantasia'] ?? $clienteData['razao_social'];
                $clienteData['nome_fantasia'] = $nomeFantasia;

                // Formata o CNPJ para garantir que só tenha números
                if (isset($clienteData['cnpj'])) {
                    $clienteData['cnpj'] = preg_replace('/\D/', '', $clienteData['cnpj']);
                }

                // Insere no eGESTOR
                $codContato = inserirClienteEgestor($clienteData);
                if (!$codContato) {
                    error_log("Erro ao inserir cliente no eGESTOR.");
                    return false;
                }
            }
        }
    }

    // Verificar se a venda já foi registrada no eGESTOR
    if ($codContato && !verificarPedidoExistenteEgestor($data['dados']['id'])) {
        error_log("Pedido não existe. Criando venda no eGESTOR...");

        // Criar o pedido no eGESTOR com o nome fantasia correto
        $egestor_data = montarDadosParaEgestor($data, $codContato);
        $access_token = obter_access_token();

        if ($access_token) {
            enviar_para_egestor($egestor_data, $access_token);
            return true;
        } else {
            error_log("Erro ao obter o access_token do eGESTOR.");
        }
    } else {
        error_log("Venda já foi registrada ou código de contato não encontrado.");
    }

    return false;
}

// Recebe o conteúdo do payload enviado pelo webhook do MERCOS
$payload = file_get_contents('php://input');

// Decodifica o JSON recebido
$data = json_decode($payload, true);

// Variável para rastrear se a venda já foi criada
$vendaCriada = false;

// Verifica se os dados foram recebidos corretamente
if (is_array($data)) {
    if (isset($data['evento'])) {
        switch ($data['evento']) {
            case 'pedido.gerado':
                error_log("Pedido gerado: " . print_r($data, true));

                if (isset($data['dados']['cliente_razao_social']) || isset($data['dados']['cliente_cnpj'])) {
                    // Obter a razão social e o CPF/CNPJ do cliente
                    $clienteRazaoSocial = $data['dados']['cliente_razao_social'] ?? 'Não informado';
                    $clienteCpfCnpj = $data['dados']['cliente_cnpj'] ?? 'Não informado';
                    
                    // Remover qualquer caractere que não seja número (deixar apenas números)
                    $clienteCpfCnpj = preg_replace('/\D/', '', $clienteCpfCnpj);

                    // Log dos dados recebidos
                    error_log("Razão social do cliente: " . $clienteRazaoSocial);
                    error_log("CPF/CNPJ do cliente (apenas números): " . $clienteCpfCnpj);
                
                    // Busca o código de contato correspondente no banco de dados
                    $codContato = buscarCodigoContatoPorCnpj($clienteCpfCnpj);
                
                    if (!$codContato) {
                        error_log("Código de contato não encontrado para a razão social ou CPF/CNPJ.");

                        if (isset($data['dados']['cliente_id'])) {
                            $clienteId = $data['dados']['cliente_id'];
                            error_log("Buscando dados do cliente com ID: " . $clienteId);

                            $clienteData = buscarClienteMercos($clienteId);

                            if ($clienteData) {
                                error_log("Dados do cliente obtidos da API do MERCOS: " . print_r($clienteData, true));

                                // Remover caracteres especiais do CNPJ antes de inserir no eGESTOR e no banco de dados
                                if (isset($clienteData['cnpj'])) {
                                    $clienteData['cnpj'] = preg_replace('/\D/', '', $clienteData['cnpj']);
                                }

                                $codContato = inserirClienteEgestor($clienteData);
                                if ($codContato) {
                                    error_log("Cliente inserido no eGESTOR com sucesso. Código: " . $codContato);
                                } else {
                                    error_log("Erro ao inserir cliente no eGESTOR.");
                                }
                            } else {
                                error_log("Erro ao buscar dados do cliente na API do MERCOS.");
                            }
                        } else {
                            error_log("Erro: cliente_id não encontrado nos dados do pedido.");
                        }
                    }

                    if ($codContato && !$vendaCriada) {
                        $access_token = obter_access_token();
                        
                        if ($access_token && verificarPedidoExistenteEgestor($data['dados']['id'], $access_token)) {
                            error_log("A venda já foi registrada no eGESTOR. Abortando duplicação.");
                            return;
                        }

                        // Processo de pagamento e envio de dados para o eGESTOR
                        $condicao_pagamento = $data['dados']['condicao_pagamento'];
                        $codFormaPgto = 0;
                        $dtVenc = $data['dados']['data_emissao'];

                        switch ($condicao_pagamento) {
                            case 'PIX':
                                $codFormaPgto = 8;
                                break;
                            case 'DINHEIRO':
                                $codFormaPgto = 1;
                                break;
                            case 'CRÉDITO':
                                $codFormaPgto = 16;
                                break;
                            case 'BOLETO 7 DIAS':
                                $codFormaPgto = 3;
                                $dtVenc = date('Y-m-d', strtotime($data['dados']['data_emissao'] . ' + 7 days'));
                                break;
                            case 'BOLETO 14 DIAS':
                                $codFormaPgto = 3;
                                $dtVenc = date('Y-m-d', strtotime($data['dados']['data_emissao'] . ' + 14 days'));
                                break;
                            case 'BOLETO 21 DIAS':
                                $codFormaPgto = 3;
                                $dtVenc = date('Y-m-d', strtotime($data['dados']['data_emissao'] . ' + 21 days'));
                                break;
                            case 'BOLETO 28 DIAS':
                                $codFormaPgto = 3;
                                $dtVenc = date('Y-m-d', strtotime($data['dados']['data_emissao'] . ' + 28 days'));
                                break;
                            default:
                                error_log("Condição de pagamento desconhecida: " . $condicao_pagamento);
                                break;
                        }

                        $criadorId = $data['dados']['criador_id'];
                        switch ($criadorId) {
                            case 662318:
                                $criadorIdTransformado = 11;
                                break;
                            case 649996:
                                $criadorIdTransformado = 1;
                                break;
                            case 662319:
                                $criadorIdTransformado = 10;
                                break;
                            case 662320:
                                $criadorIdTransformado = 22;
                                break;
                            case 662322:
                                $criadorIdTransformado = 3;
                                break;
                            case 662408:
                                $criadorIdTransformado = 8;
                                break;
                            case 662405:
                                $criadorIdTransformado = 13;
                                break;
                            default:
                                $criadorIdTransformado = null;
                                error_log("Valor de criador_id desconhecido: " . $criadorId);
                                break;
                        }

                        // Obtenha o número do criador do webhook
                        $criadorId = $data['dados']['criador_id'];

                        // Realize a conversão do número para o nome do vendedor
                        switch ($criadorId) {
                            case 662318:
                                $nome_vendedor = "João Ricardo";
                                break;
                            case 649996:
                                $nome_vendedor = "Diogo Seixas";
                                break;
                            case 662319:
                                $nome_vendedor = "Maria Eduarda";
                                break;
                            case 662320:
                                $nome_vendedor = "Maria Ramos";
                                break;
                            case 662322:
                                $nome_vendedor = "Romário Soriano";
                                break;
                            case 662408:
                                $nome_vendedor = "Roque Gomes";
                                break;
                            case 662405:
                                $nome_vendedor = "Luiza Vannier";
                                break;
                            default:
                                $nome_vendedor = "Vendedor Desconhecido"; // Valor padrão se o criador não for encontrado
                                break;
                        }

                        $egestor_data = [
                            "dtVenda" => $data['dados']['data_emissao'],
                            "dtEntrega" => $data['dados']['extras'][0]['valor_data'],  // Pega diretamente o 'valor_data' de 'extras'
                            "codVendedor" => $criadorIdTransformado ?? 1,
                            "codContato" => $codContato,
                            "tags" => ["VENDA_VIA_API", $nome_vendedor, "MERCOS_ID_" . $data['dados']['id'], $data['dados']['cliente_cidade']],
                            "valorFrete" => $data['dados']['valor_frete'] ?? 0,
                            "clienteFinal" => false,
                            "situacaoOS" => "Em espera",
                            "customizado" => [
                                "xCampo1" => $data['dados']['observacoes'],
                                "xCampo2" => $data['dados']['data_emissao']
                            ],
                            "produtos" => array_map(function ($item) {
                                switch ($item['produto_codigo']) {
                                    case 3:
                                        $codProduto = 5;
                                        break;
                                    case 1:
                                        $codProduto = 4;
                                        break;
                                    case 2:
                                        $codProduto = 3;
                                        break;
                                    case 5:
                                        $codProduto = 1;
                                        break;
                                    case 6:
                                        $codProduto = 2;
                                        break;
                                    default:
                                        $codProduto = 1;
                                        break;
                                }

                                return [
                                    "codProduto" => $item['produto_codigo'],
                                    "quant" => $item['quantidade'],
                                    "vDesc" => array_sum($item['descontos_do_vendedor'] ?? []),
                                    "deducao" => 0,
                                    "preco" => $item['preco_liquido'],
                                    "obs" => $item['observacoes']
                                ];
                            }, $data['dados']['itens']),
                            "financeiros" => [
                                [
                                    "codFormaPgto" => $codFormaPgto,
                                    "dtVenc" => $dtVenc,
                                    "codCaixa" => 2,
                                    "valor" => $data['dados']['total'],
                                    "descricao" => "Cobrança da venda de produtos",
                                    "codPlanoContas" => 1 
                                ]
                            ]
                        ];

                        // Enviar os dados moldados para o eGESTOR
                        if ($access_token) {
                            enviar_para_egestor($egestor_data, $access_token);
                            $vendaCriada = true;
                        } else {
                            error_log("Erro ao obter access_token do eGESTOR");
                        }
                    } else {
                        error_log("Erro: Código de contato não encontrado para a razão social: " . $clienteRazaoSocial);
                    }
                } else {
                    error_log("Razão social do cliente não encontrada nos dados do pedido.");
                }
                break;

            default:
                error_log("Evento desconhecido: " . print_r($data, true));
                break;
        }
    } else {
        error_log("Dados recebidos sem tipo de evento: " . print_r($data, true));
    }
} else {
    error_log("Payload inválido recebido: " . $payload);
}


// Responde com um status 200 para o webhook confirmar o recebimento
http_response_code(200);
echo json_encode(['status' => 'success']);



function obter_access_token() {
    $personal_token = getenv('EGESTOR_PERSONAL_TOKEN');

    if (!$personal_token) {
        error_log("Token pessoal do eGESTOR não definido.");
        return null;
    }

    $postData = [
        "grant_type" => "personal",
        "personal_token" => $personal_token
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.egestor.com.br/api/oauth/access_token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Erro ao obter access_token do eGESTOR: " . curl_error($ch));
        curl_close($ch);
        return null;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $token = json_decode($response, true);

    if ($httpCode !== 200) {
        error_log("Falha ao obter access_token. Código HTTP: $httpCode. Resposta: " . $response);
        return null;
    }

    if (isset($token['access_token'])) {
        error_log("Access token obtido com sucesso. Tamanho do token: " . strlen($token['access_token']));
        return $token['access_token'];
    } else {
        error_log("Erro: access_token não encontrado na resposta do eGESTOR. Resposta completa: " . $response);
        return null;
    }
}


function enviar_para_egestor($egestor_data, $access_token) {
    // Prepare the JSON data
    $json_data = json_encode($egestor_data);

    // Prepare the headers
    $headers = array(
        "Content-Type: application/json",
        "Authorization: Bearer $access_token"
    );

    // Mask the access token for logging
    $masked_token = substr($access_token, 0, 5) . '...' . substr($access_token, -5);

    // Log the headers being sent
    $headers_for_log = array(
        "Content-Type: application/json",
        "Authorization: Bearer $masked_token"
    );
    error_log("Headers being sent to eGestor API: " . print_r($headers_for_log, true));

    // Log the body being sent
    error_log("Body being sent to eGestor API: " . $json_data);

    // Existing cURL setup
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.egestor.com.br/api/v1/vendas");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    // Set the POST fields and headers
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute the request
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Erro ao enviar dados para o eGESTOR: " . curl_error($ch));
    }

    curl_close($ch);

    $response_data = json_decode($response, true);

    if (isset($response_data['errCode']) && $response_data['errCode'] == 400) {
        error_log("Erro ao criar venda: " . $response_data['errMsg']);
        error_log("Resposta completa do eGESTOR: " . print_r($response_data, true));
    } else {
        error_log("Venda criada com sucesso!");
        error_log(print_r($response_data, true));
    }
}


function buscarCodigoContatoPorCnpj($clienteCnpj) {
    global $conn; // Usar a conexão global

    // Busca o código de contato no banco de dados pelo CNPJ formatado
    $stmt = $conn->prepare("SELECT codigo_contato FROM contatos WHERE cpf_cnpj = ?");
    if (!$stmt) {
        error_log("Erro ao preparar consulta SQL: " . $conn->error);
        return null;
    }

    $stmt->bind_param("s", $clienteCnpj);
    if (!$stmt->execute()) {
        error_log("Erro ao executar consulta SQL: " . $stmt->error);
        return null;
    }

    $stmt->bind_result($codigoContato);
    $stmt->fetch();
    $stmt->close();

    if ($codigoContato) {
        return $codigoContato;
    } else {
        error_log("Código de contato não encontrado no banco de dados para o CNPJ.");
        return null;
    }
}



function buscarClienteMercos($clienteId) {
    $applicationToken = getenv('MERCOS_APPLICATION_TOKEN');
    $companyToken = getenv('MERCOS_COMPANY_TOKEN');

    if (!$applicationToken || !$companyToken) {
        error_log("Tokens do MERCOS não definidos.");
        return null;
    }

    do {
        $ch = curl_init();
        $url = "https://app.mercos.com/api/v1/clientes/$clienteId";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "ApplicationToken: $applicationToken",
            "CompanyToken: $companyToken"
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode == 429) {
            $response_data = json_decode($response, true);
            if (isset($response_data['tempo_ate_permitir_novamente'])) {
                $tempoEspera = (int)$response_data['tempo_ate_permitir_novamente'];
                error_log("Throttling ativado. Esperando $tempoEspera segundos antes de tentar novamente.");
                sleep($tempoEspera);
            }
        }
    } while ($httpCode == 429);

    if (curl_errno($ch)) {
        error_log("Erro ao buscar cliente na API do MERCOS: " . curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $clienteData = json_decode($response, true);

    if (isset($clienteData['razao_social'])) {
        // Certifique-se de que o nome_fantasia está presente no clienteData
        if (!isset($clienteData['nome_fantasia'])) {
            error_log("Nome fantasia não encontrado no retorno da API.");
        }
        return $clienteData;
    } else {
        error_log("Erro: Cliente não encontrado na API do MERCOS.");
        return null;
    }
}

function inserirClienteEgestor($clienteData) {
    $access_token = obter_access_token();

    if (!$access_token) {
        error_log("Erro ao obter access_token para inserir cliente no eGESTOR.");
        return null;
    }

    // Prepare client data
    $nomeParaContato = $clienteData['nome_fantasia'] ?? $clienteData['razao_social'];
    $email = $clienteData['emails'][0]['email'] ?? '';
    $telefone = $clienteData['telefones'][0]['numero'] ?? '';
    $numeroEntrega = is_numeric($clienteData['enderecos_adicionais']['numero'] ?? null) ? $clienteData['enderecos_adicionais']['numero'] : 0;
    $nomeFantasia = $clienteData['nome_fantasia'] ?? $clienteData['razao_social'];

    $clienteEgestor = [
        "nome" => $clienteData['razao_social'],
        "fantasia" => $nomeParaContato,
        "tipo" => ["cliente"],
        "nomeParaContato" => $nomeParaContato,
        "cpfcnpj" => $clienteData['cnpj'] ?? '',
        "emails" => [$email],
        "fones" => [$telefone],
        "cep" => $clienteData['cep'] ?? '',
        "logradouro" => $clienteData['rua'] ?? '',
        "numero" => $clienteData['numero'] ?? '',
        "complemento" => $clienteData['complemento'] ?? '',
        "bairro" => $clienteData['bairro'] ?? '',
        "uf" => $clienteData['estado'] ?? '',
        "clienteFinal" => false,
        "indicadorIE" => 1,
        "inscricaoEstadual" => $clienteData['inscricao_estadual'] ?? '',
        "obs" => $clienteData['observacoes'] ?? '',
        "cepEntrega" => $clienteData['enderecos_adicionais']['cep'] ?? '',
        "logradouroEntrega" => $clienteData['enderecos_adicionais']['endereco'] ?? '',
        "numeroEntrega" => $numeroEntrega,
        "bairroEntrega" => $clienteData['enderecos_adicionais']['bairro'] ?? '', 
        "ufEntrega" => $clienteData['enderecos_adicionais']['estado'] ?? ''
    ];

    // Convert data to JSON
    $json_data = json_encode($clienteEgestor);

    // Prepare headers
    $headers = array(
        "Content-Type: application/json",
        "Authorization: Bearer $access_token"
    );

    // Mask the access token for logging
    $masked_token = substr($access_token, 0, 5) . '...' . substr($access_token, -5);

    // Log headers and body
    $headers_for_log = array(
        "Content-Type: application/json",
        "Authorization: Bearer $masked_token"
    );
    error_log("Headers sent to eGESTOR API (Client Insertion): " . print_r($headers_for_log, true));
    error_log("Body sent to eGESTOR API (Client Insertion): " . $json_data);

    // cURL request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.egestor.com.br/api/v1/contatos");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Erro ao inserir cliente no eGESTOR: " . curl_error($ch));
    }

    curl_close($ch);

    $response_data = json_decode($response, true);

    if (isset($response_data['codigo'])) {
        error_log("Cliente inserido com sucesso no eGESTOR. Código: " . $response_data['codigo']);
        inserirNoBancoLocal($response_data['codigo'], $clienteData['razao_social'], $clienteData['cnpj']);
        return $response_data['codigo'];
    } else {
        error_log("Erro desconhecido ao inserir cliente no eGESTOR. Resposta: " . print_r($response_data, true));
        return null;
    }
}


function inserirNoBancoLocal($codigoContato, $razaoSocial, $clienteCnpj) {
    global $conn; // Usar a conexão global

    // Formatar o CNPJ para conter apenas números
    $clienteCnpjFormatado = formatarCpfCnpj($clienteCnpj);

    // Prepara a consulta para inserir os dados no banco
    $stmt = $conn->prepare("INSERT INTO contatos (codigo_contato, cliente, cpf_cnpj) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $codigoContato, $razaoSocial, $clienteCnpjFormatado);

    // Executa a inserção
    if ($stmt->execute()) {
        error_log("Cliente inserido com sucesso no banco de dados.");
    } else {
        error_log("Erro ao inserir cliente no banco de dados: " . $stmt->error);
    }

    // Fecha o statement após a execução
    $stmt->close();
}

function verificarClienteExistenteEgestor($razaoSocial) {
    $url = "https://api.egestor.com.br/api/v1/contatos?razao_social=" . urlencode($razaoSocial);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

    // Adicionar log antes da requisição
    error_log("Requisição para verificar cliente: " . $url);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Erro ao verificar cliente no eGESTOR: " . curl_error($ch));
        return null;
    }

    curl_close($ch);

    $response_data = json_decode($response, true);

    if (isset($response_data[0]['codigo'])) {
        return $response_data[0]['codigo'];
    } else {
        return null;
    }
}
// 

function formatarCpfCnpj($cpfCnpj) {
    // Remove todos os caracteres não numéricos
    return preg_replace('/[^0-9]/', '', $cpfCnpj);
}

function verificarPedidoExistenteEgestor($pedidoId, $access_token) {
    $tag = "MERCOS_ID_" . $pedidoId;
    $url = "https://api.egestor.com.br/api/v1/vendas?filtro=" . urlencode($tag) . "&fields=tags";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer $access_token"
    ));

    // Adicionar log antes da requisição
    error_log("Requisição para verificar pedido: " . $url);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Erro ao verificar pedido no eGESTOR: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $response_data = json_decode($response, true);

    if (isset($response_data['data']) && is_array($response_data['data']) && count($response_data['data']) > 0) {
        foreach ($response_data['data'] as $venda) {
            if (isset($venda['tags']) && in_array($tag, $venda['tags'])) {
                return true; // Pedido já existe
            }
        }
    }

    return false; // Pedido não encontrado
}
