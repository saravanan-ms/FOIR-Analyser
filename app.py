from flask import Flask, request, jsonify
from flask_cors import CORS
from dotenv import load_dotenv
import os
from werkzeug.utils import secure_filename
from pdf2image import convert_from_bytes
from PIL import Image
import pytesseract
import re
import json

# LangChain imports
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain.prompts import ChatPromptTemplate
from langchain.chains import LLMChain

# -------------------------------
# Load environment variables
# -------------------------------
load_dotenv()
GOOGLE_API_KEY = os.getenv("GOOGLE_API_KEY")  # Your Gemini API key

# -------------------------------
# Flask setup
# -------------------------------
app = Flask(__name__)
CORS(app)

ALLOWED_EXTENSIONS = {'pdf', 'png', 'jpg', 'jpeg'}

def allowed_file(filename):
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

def extract_text_from_file(file_storage):
    """Extract text from PDF or image using pytesseract"""
    filename = secure_filename(file_storage.filename)
    if filename.lower().endswith('pdf'):
        images = convert_from_bytes(file_storage.read())
        text = ''
        for img in images:
            text += pytesseract.image_to_string(img)
        return text
    else:
        img = Image.open(file_storage)
        return pytesseract.image_to_string(img)

def try_local_extraction(text):
    """Try extracting income and obligations using regex before calling LLM"""
    income_match = re.search(r"(?:net\s*(?:salary|income))\D+(\d{5,})", text, re.I)
    obligation_match = re.findall(r"(?:EMI|loan|credit)\D+(\d{3,6})", text, re.I)
    income = float(income_match.group(1)) if income_match else 0
    obligations = sum(float(x) for x in obligation_match) if obligation_match else 0
    return income, obligations

def parse_json_from_text(text):
    """Safely extract JSON object from LLM text"""
    text = text.strip()
    match = re.search(r"\{.*\}", text, re.DOTALL)
    if match:
        return json.loads(match.group())
    else:
        raise ValueError("LLM did not return valid JSON")

# -------------------------------
# LangChain LLM setup with Gemini
# -------------------------------
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    temperature=0,
    google_api_key=GOOGLE_API_KEY
)

# -------------------------------
# Extraction chain
# -------------------------------
extract_prompt = ChatPromptTemplate.from_messages([
    ("system", "You are a financial assistant. Extract income and obligations from documents."),
    ("human", """Text:
{full_text}

Return ONLY a JSON object like:
{{
  "income": 73300,
  "obligations": 22500
}}
""")
])
extract_chain = LLMChain(llm=llm, prompt=extract_prompt)

# -------------------------------
# Explanation chain
# -------------------------------
explain_prompt = ChatPromptTemplate.from_messages([
    ("system", "You are a loan eligibility assistant."),
    ("human", """The applicant has:
- Net Monthly Income: {income}
- Total Obligations: {obligations}
- FOIR: {foir:.2f}%

Banking rule: Eligible if FOIR <= 50%.

Explain in 20 words or less whether the applicant is eligible for a loan.
""")
])
explain_chain = LLMChain(llm=llm, prompt=explain_prompt)

# -------------------------------
# Flask route
# -------------------------------
@app.route("/analyze", methods=["POST"])
def analyze():
    try:
        if 'documents' not in request.files:
            return jsonify({"error": "No documents uploaded"}), 400

        files = request.files.getlist("documents")
        full_text = ""

        for file in files:
            if file and allowed_file(file.filename):
                full_text += "\n" + extract_text_from_file(file)

        if not full_text.strip():
            return jsonify({"error": "Could not extract text from documents"}), 400

        # Step 1: Local regex extraction
        income, obligations = try_local_extraction(full_text)

        # Step 2: LLM extraction if regex fails
        if income <= 0:
            llm_result = extract_chain.run(full_text=full_text)
            try:
                data = parse_json_from_text(llm_result)
                income = float(data.get("income", 0))
                obligations = float(data.get("obligations", 0))
            except ValueError:
                return jsonify({"error": "Could not parse JSON from LLM"}), 500

        if income <= 0:
            return jsonify({"error": "Could not detect income from documents"}), 400

        # Step 3: FOIR calculation
        foir = (obligations / income) * 100
        eligible = foir <= 50

        # Step 4: Explanation via LLMChain
        explanation = explain_chain.run(income=income, obligations=obligations, foir=foir)

        return jsonify({
            "status": "success",
            "income": income,
            "obligations": obligations,
            "foir": round(foir, 2),
            "eligible": eligible,
            "explanation": explanation
        })

    except Exception as e:
        return jsonify({"error": str(e)}), 500

# -------------------------------
# Run app
# -------------------------------
if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8001, debug=True)
