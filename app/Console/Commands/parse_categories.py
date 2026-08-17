import openpyxl
import json
import os
import sys

def main():
    file_path = '/Users/radhe/Documents/shopify_app/Category.xlsx'
    if not os.path.exists(file_path):
        print(json.dumps({"error": f"Category.xlsx not found at {file_path}"}))
        sys.exit(1)

    try:
        wb = openpyxl.load_workbook(file_path, data_only=True)
        sheet = wb.active # Get active sheet
        rows = list(sheet.iter_rows(values_only=True))

        categories = []
        for r in rows[3:]:  # Header is at row 3 (0-indexed 2)
            name, parent, child = r[0], r[1], r[2]
            if name:
                categories.append({
                    "name": str(name).strip(),
                    "parent": str(parent).strip() if parent else None,
                    "child": str(child).strip() if child else None
                })

        print(json.dumps(categories))
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == '__main__':
    main()
