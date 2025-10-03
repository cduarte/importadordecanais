# 📦 Importador M3U para XUI.ONE

Sistema profissional para importação de listas **M3U** diretamente no **XUI.ONE**, com categorização automática e prevenção de duplicados.

## 🚀 Estrutura do Projeto

- **/cliente**  
  Contém os arquivos da interface (frontend).  
  Nesta pasta está o formulário onde o usuário preenche os dados do banco de dados e a URL da lista M3U para realizar a importação de forma rápida e segura.

- **/server**  
  Contém os arquivos de processamento (backend).  
  Esses arquivos recebem as requisições enviadas pelos formulários da pasta `cliente` e realizam a lógica de importação, inserindo os canais e categorias no **XUI.ONE**.

## ⚙️ Funcionalidades

- Importação direta de listas **M3U** para o banco do XUI.ONE  
- Categorização automática dos canais  
- Prevenção de duplicados durante a importação  
- Feedback em tempo real sobre o resultado do processo  

## 📝 Requisitos

- Servidor com **PHP 7.4+**
- Banco de dados **MySQL/MariaDB**
- Acesso ao **XUI.ONE**

### ⏱️ Ajustando o tempo limite de download da M3U

Algumas listas M3U podem demorar vários minutos para serem transferidas. O backend respeita a variável de ambiente `IMPORTADOR_M3U_TIMEOUT` (em segundos) para definir o tempo limite utilizado ao baixar a lista e para o `default_socket_timeout` do PHP. Caso não seja definido, o sistema utiliza 600 segundos (10 minutos). Ajuste esse valor conforme a velocidade do servidor de origem e o tamanho da lista.

---

💡 Ideal para administradores que desejam integrar listas M3U ao **XUI.ONE** de forma simples, segura e organizada.
