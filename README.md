# 📦 Importador M3U para XUI.ONE

Sistema profissional para importação de Fonte de listas **M3U** diretamente no **XUI.ONE**, com categorização automática e prevenção de duplicados.

## 🚀 Estrutura do Projeto

- **/cliente**  
  Contém os arquivos da interface (frontend).  
  Nesta pasta está o formulário onde o usuário preenche os dados do banco de dados e a URL da lista M3U para realizar a importação de forma rápida e segura.

- **/server**
  Contém os arquivos de processamento (backend).
  Esses arquivos recebem as requisições enviadas pelos formulários da pasta `cliente` e realizam a lógica de importação, inserindo os canais e categorias no **XUI.ONE**.
  Agora os jobs são enfileirados na tabela `clientes_import_jobs` com o campo `job_type` indicando se o processamento é de **filmes** ou **canais**, permitindo que cada worker atue apenas no tipo correspondente.

## ⚙️ Funcionalidades

- Importação direta de listas **M3U** para o banco do XUI.ONE  
- Categorização automática dos canais  
- Prevenção de duplicados durante a importação
- Feedback em tempo real sobre o resultado do processo

### ⚡ Estratégia de cache e deduplicação

Todos os workers carregam em memória as fontes (`stream_source`) já existentes antes de iniciar cada importação. Esse cache evita
consultas repetitivas ao banco durante o processamento e garante que duplicados sejam descartados rapidamente tanto para canais
quanto para filmes e séries.

### 👷 Workers disponíveis

Execute manualmente os workers passando o `job_id` correspondente:

```bash
php server/worker_process_filmes.php <job_id>
php server/worker_process_canais.php <job_id>
```

Cada worker valida o tipo do job antes de iniciar o processamento.

## 📝 Requisitos

- Servidor com **PHP 7.4+**
- Banco de dados **MySQL/MariaDB**
- Acesso ao **XUI.ONE**

## 🌐 Importador API Proxy

Este projeto implementa um **proxy em PHP** (`cliente/api_proxy.php`) que redireciona requisições locais para uma **API remota**. Ele serve para encapsular chamadas aos endpoints de importação sem expor diretamente a URL do servidor de destino.

### 🚀 Como funciona

- O arquivo principal é `api_proxy.php`.
- Você chama a URL local passando o parâmetro `endpoint`.
- O proxy lê a variável `IMPORTADOR_API_BASE_URL` (definida em um arquivo `.env`) e encaminha a requisição para a API remota.

Exemplo:

```
https://seusite.com/api_proxy.php?endpoint=filmes
```

É redirecionado internamente para:

```
https://45.67.136.10/~joaopedro/process_filmes.php
```

### ⚙️ Configuração

1. **Criar arquivo `.env`**

   Na mesma pasta onde está o `api_proxy.php`, crie um arquivo chamado `.env` com o seguinte conteúdo:

   ```
   IMPORTADOR_API_BASE_URL=https://45.67.136.10/~joaopedro/
   ```

   > ⚠️ Importante: sempre terminar com `/` no final da URL.

2. **Endpoints disponíveis**

   O parâmetro `endpoint` aceita os seguintes valores:

   - `canais` → chama `process_canais.php`
   - `canais_status` → chama `process_canais_status.php`
   - `filmes` → chama `process_filmes.php`
   - `filmes_status` → chama `process_filmes_status.php`

   Exemplos:

   ```
   api_proxy.php?endpoint=canais
   api_proxy.php?endpoint=filmes
   ```

3. **Métodos suportados**

   - **GET** → parâmetros passados na query string.
   - **POST** → suporta envio de dados `form-data`, incluindo upload de arquivos.

4. **Respostas**

   - Caso o endpoint remoto responda com JSON, o proxy repassa o mesmo JSON.
   - Caso ocorra erro, o proxy retorna:
     - `400` → endpoint inválido.
     - `404` → endpoint desconhecido.
     - `405` → método não suportado.
     - `500` → variável de ambiente não configurada.
     - `502` → falha ao contactar o servidor remoto.

### 🔒 Notas importantes

- **Não existe pasta `/server` localmente exposta** neste projeto.
- Todas as chamadas são redirecionadas **apenas para a API remota** definida em `IMPORTADOR_API_BASE_URL`.
- O fallback de `require` local foi removido para evitar erros de configuração.

### ⏱️ Ajustando o tempo limite de download da M3U

Algumas listas M3U podem demorar vários minutos para serem transferidas. O backend respeita a variável de ambiente `IMPORTADOR_M3U_TIMEOUT` (em segundos) para definir o tempo limite utilizado ao baixar a lista e para o `default_socket_timeout` do PHP. Caso não seja definido, o sistema utiliza 600 segundos (10 minutos). Ajuste esse valor conforme a velocidade do servidor de origem e o tamanho da lista.

### 🖥️ Selecionando o binário PHP CLI

Quando o `process_canais.php` ou `process_filmes.php` são executados a partir de um ambiente que não seja CLI (por exemplo, `php-fpm`), os scripts precisam chamar os workers em background com um binário de linha de comando. Caso o PHP detecte que está rodando fora do CLI, ele tentará utilizar automaticamente:

1. O caminho definido na variável de ambiente `IMPORTADOR_PHP_CLI` (se configurada).
2. O binário `php` dentro de `PHP_BINDIR`.
3. O comando `php` disponível no `PATH` do sistema.

Configure `IMPORTADOR_PHP_CLI` para forçar o uso de uma versão específica do PHP CLI quando necessário.

---

💡 Ideal para administradores que desejam integrar listas M3U ao **XUI.ONE** de forma simples, segura e organizada.
