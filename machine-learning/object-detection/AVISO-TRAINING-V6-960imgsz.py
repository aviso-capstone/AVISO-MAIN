# ============================================================
# CELL 1: Verify GPU is active
# In your Kaggle notebook settings (right sidebar), confirm
# Accelerator is set to "GPU T4 x2" or "GPU T4", NOT TPU.
# Also confirm "Internet" is toggled ON in the same settings
# panel, it's off by default and pip installs will fail
# silently without it.
# ============================================================
# nvidia-smi | Commented out since nag eerror kapag sa vscode

# ============================================================
# CELL 2: Install dependencies
# ============================================================
# pip install -q ultralytics roboflow | Commented out since nag eerror kapag sa vscode

# ============================================================
# CELL 3: Pull your finalized, pre-split dataset from Roboflow
# Kaggle has no Google Drive mount — everything saves to
# /kaggle/working/ by default, and only persists if you
# commit your notebook version before the session ends.
# ============================================================
from roboflow import Roboflow

rf = Roboflow(api_key="YOUR_ROBOFLOW_API_KEY")
project = rf.workspace("aldrians-workspace").project("v1-local-and-online-datasets-12-classess")
dataset = project.version(5).download("yolov8")  # match your actual generated version number

with open(f"{dataset.location}/data.yaml", "r") as f:
    print(f.read())
# Confirm this prints your real, final class list — including
# your current decision to keep No Parking specific and fold
# Reduce Speed into "Other Traffic Sign" — before proceeding.

from ultralytics import YOLO
model = YOLO('yolov8n.pt')

# ============================================================
# CELL 5: Train — v6, imgsz test
# Single-variable test: imgsz raised 640 → 960 to check whether
# input resolution, not just data, is limiting small-object
# detection (Pothole specifically). patience held at 25
# (unchanged from v5) so imgsz is the only variable changing —
# NOT combined with the patience-15 idea, to keep this isolated.
# Expect a meaningfully longer run time per epoch at 960.
# ============================================================
results = model.train(
    data=f"{dataset.location}/data.yaml",
    epochs=150,
    imgsz=960,
    batch=16,
    patience=15,
    device=[0, 1],
    project='/kaggle/working/AVISO_training',
    name='aviso_yolov8n_v6',
    exist_ok=True
)

# ============================================================
# CELL 6: Evaluate against your held-out TEST split
# ============================================================
metrics = model.val(
    data=f"{dataset.location}/data.yaml",
    split='test'
)

print("Overall mAP@0.5:", metrics.box.map50)
print("Overall mAP@0.5:0.95:", metrics.box.map)
print("Overall Precision:", metrics.box.mp)
print("Overall Recall:", metrics.box.mr)

print("\nPer-class mAP@0.5:")
for i, class_map50 in enumerate(metrics.box.ap50):
    print(f"  Class {i}: {class_map50:.3f}")

print("\nPer-class Precision and Recall:")
for i in range(len(metrics.box.p)):
    print(f"  Class {i}  Precision: {metrics.box.p[i]:.3f}  Recall: {metrics.box.r[i]:.3f}")

from IPython.display import Image, display
import glob

cm_path = glob.glob(f"{metrics.save_dir}/confusion_matrix.png")
if cm_path:
    display(Image(filename=cm_path[0]))

pr_path = glob.glob(f"{metrics.save_dir}/PR_curve.png")
if pr_path:
    display(Image(filename=pr_path[0]))
    
# ============================================================
# CELL 7: Export to TFLite for your Android app
# ============================================================
model.export(format='tflite')

# ============================================================
# CELL 8: Confirm your files are actually retrievable
# On Kaggle, this means checking they're inside /kaggle/working/,
# then downloading via the notebook's Output panel, or
# committing this notebook version so the files persist.
# ============================================================
import os

for root, dirs, files in os.walk('/kaggle/working/AVISO_training'):
    for f in files:
        print(os.path.join(root, f))

print("\nGo to the Output panel on the right side of this notebook")
print("to download best.pt and the exported .tflite file, or")
print("click 'Save Version' to commit this run so it persists.")

# ============================================================
# CELL 9: Sanity check your class list against your app code
# This is where the earlier flagged bug actually gets caught —
# your React Native class-name array must match this exact
# order, or a correct detection displays the wrong label.
# ============================================================
import yaml

with open(f"{dataset.location}/data.yaml", "r") as f:
    data_config = yaml.safe_load(f)

print("Class order your model was trained on, index by index:")
for i, name in enumerate(data_config['names']):
    print(f"  {i}: {name}")
print("\nCopy this exact order into your app's class-name array.")