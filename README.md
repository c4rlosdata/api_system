Aqui está um README completo para o seu projeto de integração entre as APIs do CRM Mercos e do ERP eGestor, com utilização de um webhook:

---

# Mercos & eGestor API Integration with Webhook Handling

This project integrates two APIs, **Mercos** (a CRM system) and **eGestor** (an ERP system), to automate data exchange and synchronization between the two systems. The integration is designed to streamline processes by automating data transfers such as client and order information. The system relies on a webhook setup in Mercos, which triggers the integration whenever specific events occur.

## Table of Contents
1. [Project Overview](#project-overview)
2. [Features](#features)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Usage](#usage)
7. [File Structure](#file-structure)
8. [Troubleshooting](#troubleshooting)
9. [Future Enhancements](#future-enhancements)
10. [License](#license)

---

## Project Overview
The main goals of this project are:
- **Automate client and order data synchronization** between Mercos and eGestor.
- **Use webhooks to trigger events**: Whenever a new order is created in Mercos, the webhook forwards the data to this integration script.
- **Client Verification and Creation**: Check if the client exists in eGestor. If not, the client is created in the ERP system.
- **Order Creation**: Once the client is confirmed, create an order in eGestor.

## Features
- **Webhook Integration**: Listens for events from the Mercos CRM to trigger data synchronization.
- **Client Verification and Insertion**: Automatically checks if a client exists in eGestor and inserts the client if needed.
- **Order Synchronization**: Creates a new order in eGestor with the appropriate client and transaction details.
- **Error Handling and Logging**: Logs errors and events for easier debugging and monitoring.
- **Transaction Locking**: Ensures that each webhook request is processed sequentially to avoid conflicts.

## Requirements
- **PHP 7.4+**
- **MySQL Database**: For storing transaction locks and syncing client data.
- **APIs**: Access to the Mercos and eGestor APIs.
- **cURL** and **JSON support in PHP** for API requests.

## Installation
1. **Clone this repository**:
   ```bash
   git clone https://github.com/yourusername/mercos-egestor-integration.git
   cd mercos-egestor-integration
   ```
2. **Database Setup**:
   - Ensure you have a MySQL database configured with a `locks` table to manage transaction locks.
   - Set up a `contatos` table if storing client contact data locally.

3. **Environment Variables**:
   - Update the `.env` file with tokens for both Mercos and eGestor.

## Configuration
1. **Database Configuration**:
   - Set your database credentials in the `webhook_receiver.php` file:
     ```php
     $host = 'your_db_host';
     $port = 'your_db_port';
     $user = 'your_db_user';
     $password = 'your_db_password';
     $dbname = 'your_db_name';
     ```

2. **API Tokens**:
   - Set the API tokens in your environment variables (or `.env`):
     - `MERCOS_APPLICATION_TOKEN` and `MERCOS_COMPANY_TOKEN` for Mercos API.
     - `EGESTOR_PERSONAL_TOKEN` for eGestor API authentication.

## Usage
1. **Setting Up the Webhook**:
   - In your Mercos CRM, configure a webhook to point to `webhook_receiver.php`.
   - Configure the webhook to trigger for `pedido.gerado` events to start order processing upon new order creation.

2. **Process Workflow**:
   - When a `pedido.gerado` event is received, the script:
     - Verifies the client data from Mercos in eGestor.
     - If the client does not exist in eGestor, it is created based on Mercos data.
     - Once the client is confirmed, the script creates an order in eGestor with appropriate transaction details (e.g., payment terms, creator, tags).
   - The response from eGestor is logged for review.

3. **Lock Management**:
   - A lock is placed on the database to prevent simultaneous processing of multiple webhook events. This ensures that each request is processed sequentially.

## File Structure
```
├── webhook_receiver.php         # Main script for handling webhook events and data synchronization
├── .env                         # Environment file for API tokens and configurations
└── README.md                    # Project documentation
```

## Troubleshooting
- **Webhook Not Triggering**: Ensure that the Mercos webhook is correctly configured to send events to the `webhook_receiver.php` endpoint.
- **Database Connection Issues**: Confirm that your MySQL credentials are correct and that the `locks` table is set up.
- **API Token Errors**: Ensure the Mercos and eGestor tokens are correct in the environment configuration and have sufficient permissions.
- **Rate Limiting**: Mercos API may limit requests. If you receive a 429 error, the script will wait and retry based on the rate limit.

## Future Enhancements
- **Improved Error Reporting**: Enhance error logs to store detailed information on failed transactions.
- **Order Status Updates**: Extend functionality to update orders if they are modified in Mercos.
- **Enhanced Locking Mechanism**: Implement more robust transaction management.

## License
This project is licensed under the MIT License.

---

This README provides a complete guide to your integration project, from setup to troubleshooting. Let me know if you'd like further customization!
