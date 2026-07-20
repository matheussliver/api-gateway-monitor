# API Gateway Monitor

Serviço Laravel para importar logs NDJSON do API Gateway de forma incremental, persistir os dados no MySQL com timestamps auditáveis e gerar relatórios CSV agregados.

## Stack

- PHP 8.4
- Laravel 13
- MySQL 8.4
- Nginx + PHP-FPM
- Docker Compose
- PHPUnit

Não há frontend. Toda a operação é realizada por comandos Artisan.

## Funcionalidades

- Leitura de NDJSON linha por linha, com uso controlado de memória;
- Validação e normalização de cada registro;
- Importação incremental por offset de byte;
- Persistência em lotes transacionais;
- Retomada segura após interrupções;
- Registro auditável de linhas rejeitadas sem interromper os registros seguintes;
- Proteção contra duplicação e importações simultâneas;
- Registro separado da ocorrência (`created_at`) e do processamento (`processed_at`);
- Relatórios CSV por consumidor, por serviço e por latência média.

## Mapeamento dos dados

O arquivo real possui duas diferenças em relação ao payload de referência da tarefa: o consumidor está aninhado em `consumer_id.uuid` e `started_at` está em segundos Unix. Para manter compatibilidade com ambos, o normalizador aceita o UUID tanto diretamente em `consumer_id` quanto em `consumer_id.uuid`, além de timestamps em segundos ou milissegundos.

| Origem no NDJSON | Destino | Regra |
| --- | --- | --- |
| `authenticated_entity.consumer_id` ou `.consumer_id.uuid` | `consumer_id` | UUID obrigatório |
| `service.name` | `service_name` | Texto não vazio com até 255 caracteres |
| `latencies.request` | `latency_request` | Inteiro entre 0 e 4.294.967.295 |
| `latencies.proxy` | `latency_proxy` | Inteiro entre 0 e 4.294.967.295 |
| `latencies.gateway` | `latency_gateway` | Inteiro entre 0 e 4.294.967.295 |
| `started_at` | `created_at` | Timestamp Unix em segundos ou milissegundos, convertido para UTC |
| Momento da inserção | `processed_at` | Gerado em UTC para cada lote persistido |

Linhas vazias, JSON malformado, UUID inválido, campos ausentes, tipos incorretos e latências negativas são rejeitados individualmente. Cada rejeição é persistida com fonte, linha, byte, motivo e horário do processamento; os registros seguintes continuam sendo examinados. Logs válidos e rejeições são confirmados na mesma transação do checkpoint.

Uma linha somente é considerada completa quando termina com `LF` (`\n`). Se o
arquivo terminar no meio de uma linha, esse segmento final não é rejeitado nem
incluído no checkpoint; ele permanece pendente até que uma execução posterior
encontre o terminador.

## Requisitos

- Docker com o plugin Docker Compose;
- Portas `8080` e `3306` disponíveis, ou valores alternativos configurados no `.env`;
- O arquivo NDJSON deve estar dentro do diretório do projeto, ou em outro caminho montado no container.

PHP, Composer, Nginx e MySQL não precisam estar instalados no host.

## Instalação com Docker

Clone o repositório e entre no diretório do projeto. Em seguida:

```bash
cp .env.example .env
```

Instale as dependências sem depender de PHP ou Composer no host:

```bash
docker compose run --rm --no-deps \
  --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  --entrypoint composer app install --no-interaction
```

Suba os serviços:

```bash
docker compose up -d
```

Gere a chave da aplicação e execute as migrations:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

Confira a stack:

```bash
docker compose ps
curl http://localhost:8080/up
```

A aplicação estará disponível em `http://localhost:8080`.

## Configuração

As principais variáveis estão no `.env.example`:

| Variável | Padrão | Finalidade |
| --- | --- | --- |
| `APP_PORT` | `8080` | Porta HTTP publicada no host |
| `DB_FORWARD_PORT` | `3306` | Porta MySQL publicada no host |
| `DB_DATABASE` | `gateway_logs` | Banco de desenvolvimento |
| `DB_TEST_DATABASE` | `gateway_logs_test` | Banco exclusivo dos testes |
| `DB_USERNAME` | `gateway_user` | Usuário MySQL da aplicação |
| `DB_PASSWORD` | `gateway_password` | Senha local da aplicação |
| `DB_ROOT_PASSWORD` | `root_password` | Senha root local do MySQL |

Os valores padrão são destinados somente ao desenvolvimento local.

Se uma porta estiver ocupada, altere apenas o valor publicado no `.env`, por exemplo:

```dotenv
APP_PORT=8081
DB_FORWARD_PORT=3307
```

Dentro da rede Docker, Laravel continua acessando o MySQL por `mysql:3306`.

## Importação incremental

Coloque o arquivo na raiz do projeto, sem adicioná-lo ao Git, e execute:

```bash
docker compose exec app php artisan gateway-logs:import logs.txt
```

O lote padrão contém `1.000` registros. É possível usar um valor entre `1` e `5.000`:

```bash
docker compose exec app php artisan gateway-logs:import logs.txt --batch=2000
```

O comando apresenta registros importados e rejeitados, linhas, bytes, tamanho do arquivo, duração e pico de memória. Quando existem rejeições, cada linha e seu motivo são exibidos ao final. Todo o arquivo ainda é processado, mas o comando retorna código `1` para que automações identifiquem que o resultado não foi integralmente válido.

### Comportamento incremental

Cada arquivo é identificado pelo caminho canônico e pela identidade do arquivo no sistema operacional. Para cada fonte, o banco mantém:

- Último byte processado;
- Última linha processada;
- Tamanho observado do arquivo;
- Identidade da fonte.

Ao executar novamente o comando:

- Arquivo inalterado: zero inserções;
- Linhas adicionadas: somente o novo trecho é processado;
- Linha final sem `LF`: permanece pendente até ser concluída;
- Arquivo truncado: a execução é recusada;
- Mesma fonte em duas execuções simultâneas: a segunda é recusada;
- Registro inválido: a rejeição é auditada e a leitura continua;
- Reinício após falha de infraestrutura: a leitura retorna ao último lote confirmado.

Logs e rejeições possuem restrições únicas por fonte e offset, oferecendo uma segunda proteção contra duplicação. Como o checkpoint avança sobre linhas válidas e rejeitadas, corrigir posteriormente o conteúdo de uma linha já rejeitada não a reprocessa automaticamente; a rejeição permanece como registro auditável daquela ingestão.

## Relatórios CSV

Gere os três relatórios no diretório padrão:

```bash
docker compose exec app php artisan gateway-logs:reports
```

Por padrão, os arquivos são gravados em `storage/app/reports`:

- `requests_by_consumer.csv` — total de requisições por `consumer_id`;
- `requests_by_service.csv` — total de requisições por serviço;
- `average_latency_by_service.csv` — médias de `request`, `proxy` e `gateway` por serviço.

Também é possível informar outro diretório dentro do container:

```bash
docker compose exec app php artisan gateway-logs:reports storage/app/custom-reports
```

Os CSVs usam UTF-8, vírgula como separador, ponto decimal, duas casas nas médias, campos corretamente escapados, final de linha `LF` e ordenação determinística. A regeneração substitui cada arquivo de forma atômica.

## Testes

O projeto usa um banco MySQL exclusivo chamado `gateway_logs_test`. O bootstrap de testes força essa conexão antes de inicializar o Laravel, impedindo que `RefreshDatabase` alcance o banco de desenvolvimento.

Execute a suíte completa:

```bash
docker compose exec app php artisan test
```

Valide o estilo sem modificar arquivos:

```bash
docker compose exec app vendor/bin/pint --test
```

### Benchmark dos lotes

O benchmark de importação fica fora das suítes Unit e Feature para não tornar a
execução cotidiana dependente do arquivo real nem executar repetidamente uma
carga de 100.000 linhas. Ele sempre usa o banco isolado `gateway_logs_test` e
executa `migrate:fresh` antes de cada tamanho de lote.

Para comparar os lotes `1`, `100`, `1.000`, `5.000`, `10.000` e `100.000`:

```bash
docker compose exec -T app php artisan test \
  tests/Benchmark/GatewayLogBatchPerformanceTest.php
```

O teste mede tempo, registros por segundo, pico total e incremento aproximado de
memória. Os resultados também são gravados localmente em
`storage/app/benchmarks/batch-import-performance.csv`.

Os lotes `10.000` e `100.000` são exercitados diretamente no serviço de importação
para descobrir se os limites reais do PHP ou MySQL são atingidos; eles não
alteram o limite de segurança `5.000` exposto pelo comando operacional.

Para validar rapidamente o mecanismo com uma fixture pequena ou selecionar
somente alguns lotes:

```bash
docker compose exec -T \
  -e BENCHMARK_LOG_PATH=tests/Fixtures/Logs/valid-seconds.ndjson \
  -e BENCHMARK_BATCH_SIZES=1,1000,100000 \
  app php artisan test tests/Benchmark/GatewayLogBatchPerformanceTest.php
```

`BENCHMARK_REPORT_PATH` permite direcionar uma execução auxiliar para outro CSV.
`BENCHMARK_APPEND_RESULTS=1` preserva um relatório existente e acrescenta os
novos datasets, sendo útil para diagnosticar um lote isoladamente.

#### Resultado no arquivo fornecido

Medição realizada com PHP limitado a `256 MB`, MySQL 8.4 e os 100.000 registros
do `logs.txt`:

| Lote | Resultado | Tempo | Registros/s | Pico de memória |
| ---: | --- | ---: | ---: | ---: |
| `1` | Concluído | `829,717 s` | `120,52` | `44,50 MB` |
| `100` | Concluído | `19,487 s` | `5.131,67` | `46,50 MB` |
| `1.000` | Concluído | `11,588 s` | `8.629,64` | `50,50 MB` |
| `5.000` | Concluído | `9,602 s` | `10.414,30` | `80,50 MB` |
| `10.000` | Falhou | `8,903 s` até a falha | — | `104,51 MB` |
| `100.000` | Falhou | não mensurado | — | limite de `256 MB` atingido |

O lote `10.000` produz 100.000 placeholders no `INSERT` de dez colunas e o
MySQL o rejeita com o erro `Prepared statement contains too many placeholders`.
O lote `100.000` esgota a memória enquanto ainda acumula os registros, antes de
tentar persistir. O lote `5.000` ficou abaixo do limite de placeholders e foi o
mais rápido entre os que concluíram corretamente neste ambiente, consumindo
aproximadamente `30 MB` a mais de pico que o lote `1.000`.

Como os dois maiores casos são deliberadamente exploratórios e encontram
limites reais, a execução completa do benchmark termina com código diferente de
zero. O CSV conserva os resultados dos casos anteriores em
`storage/app/benchmarks/batch-import-performance.csv`.

Os testes cobrem:

- Parser NDJSON e validações de domínio;
- Timestamps em segundos e milissegundos;
- Persistência e precisão de milissegundos;
- Transações e checkpoints;
- Continuidade após JSON inválido ou campo obrigatório ausente;
- Persistência e idempotência das rejeições;
- Reexecução, append, truncamento e concorrência;
- Códigos de saída e mensagens dos comandos;
- Conteúdo exato dos CSVs;
- Banco vazio, escaping e regeneração dos relatórios;
- Integração real com MySQL.

## Estrutura principal

```text
app/
├── Application/
│   ├── LogImport/       # Importação incremental e checkpoints
│   └── Reports/         # Consultas agregadas e escrita dos CSVs
├── Console/Commands/    # Interface operacional via Artisan
├── Domain/GatewayLog/   # DTO, normalização e regras do log
├── Infrastructure/      # Parser NDJSON
└── Models/              # Persistência Eloquent

tests/
├── Fixtures/            # Casos NDJSON pequenos e versionáveis
├── Unit/                # Parser e normalizador
└── Feature/             # MySQL, importador, comandos e relatórios
```

## Modelo de persistência

`log_sources` mantém a identidade e o checkpoint de cada arquivo. `gateway_logs` contém os dados normalizados e auditáveis usados nos relatórios. `gateway_log_rejections` registra a posição, o motivo e o horário de cada linha inválida.

Índices foram adicionados para consumidor, serviço, datas, rejeições e chaves estrangeiras. A combinação `log_source_id + source_offset` é única tanto nos logs quanto nas rejeições.

O modelo de logs desabilita os timestamps automáticos do Eloquent para impedir que o framework sobrescreva o `created_at` original. `processed_at` é preenchido explicitamente no momento da inserção.

## Resultados de referência

Com o arquivo fornecido de `100.000` linhas e aproximadamente `118 MB`:

- `100.000` registros importados;
- `9.999` consumidores únicos;
- `5` serviços;
- Importação inicial em aproximadamente `12,2 s`;
- Pico de memória da importação: `34 MB`;
- Reexecução sem alterações: zero inserções em aproximadamente `0,05 s`;
- Geração dos três relatórios em aproximadamente `1,4 s`;
- Pico de memória dos relatórios: `26 MB`.

Esses números são apenas uma referência do ambiente local e podem variar conforme hardware, Docker e armazenamento.

## Operação da stack

Parar os containers preservando o banco:

```bash
docker compose down
```

Visualizar logs dos serviços:

```bash
docker compose logs -f app mysql
```

Remover também o volume do banco apaga permanentemente todos os registros:

```bash
docker compose down -v
```

Use o último comando somente quando desejar reinicializar completamente o ambiente.
