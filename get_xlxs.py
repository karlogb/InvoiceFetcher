# © 2024 Karol Bolgár. All rights reserved

from google.oauth2.service_account import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload
import io
import pandas as pd
import requests
import json
from datetime import datetime, timezone, timedelta

# Service account
service_account_info = {
}

# Google API
credentials = Credentials.from_service_account_info(service_account_info)

# Build Google Drive Service
service = build('drive', 'v3', credentials=credentials)

# UTC Time
today = datetime.now(timezone.utc).date()

# Search for files in the folder uploaded today
folder_id = '' # Folder ID
query = f"'{folder_id}' in parents and (mimeType='application/vnd.ms-excel.sheet.macroEnabled.12' or mimeType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') and modifiedTime >= '{today}T00:00:00Z' and modifiedTime < '{today + timedelta(days=1)}T00:00:00Z'"
response = service.files().list(q=query, spaces='drive', orderBy='createdTime desc').execute()
files = response.get('files', [])

combined_data = []

# Download all files uploaded today
if not files:
    print("No files found.")
else:
    for file in files:
        file_id = file.get('id')
        request = service.files().get_media(fileId=file_id)
        fh = io.BytesIO()
        downloader = MediaIoBaseDownload(fh, request)
        done = False
        while done is False:
            status, done = downloader.next_chunk()
        fh.seek(0)

        sheet = 'Daily'
        
        xl = pd.ExcelFile(fh)
        if(len(xl.sheet_names) == 1):
            sheet = xl.sheet_names[0]

        print(xl.sheet_names)

        # Load file with pandas
        excel_data = pd.read_excel(fh, sheet_name=sheet, header=None)
        
        # Extracting data from row 9 onwards from columns C, D and F
        data_col_c = excel_data.iloc[8:, 2].dropna().astype(str).tolist()
        data_col_d = excel_data.iloc[8:, 3].dropna().astype(str).tolist()
        data_col_f = excel_data.iloc[8:, 5].dropna().astype(str).tolist()

        # Merging data into a single structure and date formatting
        for c, d, f in zip(data_col_c, data_col_d, data_col_f):
            formatted_date = d.split()[0]  # YYYY-MM-DD
            combined_data.append({
                "id": c,
                "date": formatted_date,
                "currency": f
            })
        
        # print(f"Data from file {file.get('name')}: {combined_data}")

    # print(f"All combined data: {combined_data}")

if(len(combined_data) > 0):
    url = "http://localhost:8080/"
    headers = {'Content-Type': 'application/json'}
    payload = json.dumps({"data": combined_data})

    response = requests.get(url, headers=headers, data=payload)
else:
    print("No data.")
