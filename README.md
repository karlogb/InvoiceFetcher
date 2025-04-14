# 📊 SuperFaktury Data Processor

This project is designed to automatically download `.xlsx` files from a specific Google Drive folder, process the data, and send it to a PHP backend for further handling.

## 🛠️ Technologies Used

- Python (`get_xlxs.py`) – for downloading and processing data from Excel files
- Google Drive API
- Pandas
- Requests
- PHP (`index.php`) – backend endpoint for receiving data
- Composer – for managing PHP dependencies

## 🔧 Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/your-username/superfaktury.git
   ```

2. Install Python dependencies:
   ```bash
   pip install -r requirements.txt
   ```

3. Install PHP dependencies via Composer:
   ```bash
   composer install
   ```

4. Create a `.env` file based on this format:
   ```
   # .env
   GOOGLE_SERVICE_ACCOUNT_JSON=...
   ```

5. Run the Python script:
   ```bash
   python get_xlxs.py
   ```

## 📁 Project Structure

```
SuperFaktury/
├── get_xlxs.py           # Python script for data extraction from GDrive
├── index.php             # PHP endpoint to receive processed data
├── composer.json         # PHP dependency definitions
├── composer.lock
```


## 📄 License

© 2024 Karol Bolgár. All rights reserved.
