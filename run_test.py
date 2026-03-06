import uvicorn
import importlib.util
import sys
import os

if __name__ == "__main__":
    # Dynamically load api-test.py module
    spec = importlib.util.spec_from_file_location("api_test", "./api-test.py")
    api_test = importlib.util.module_from_spec(spec)
    sys.modules["api_test"] = api_test
    spec.loader.exec_module(api_test)
    
    uvicorn.run(api_test.app, host="0.0.0.0", port=8001, reload=True)
