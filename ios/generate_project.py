#!/usr/bin/env python3
import os
import hashlib

def gen_id(name):
    # Generates a standard 24-character hexadecimal Xcode UUID
    return hashlib.md5(name.encode('utf-8')).hexdigest()[:24].upper()

root_dir = os.path.abspath(os.path.dirname(__file__))
app_dir = os.path.join(root_dir, "AiPBX")

swift_files = []
resource_files = []

for dirpath, _, filenames in os.walk(app_dir):
    for f in sorted(filenames):
        if f.endswith('.swift'):
            rel = os.path.relpath(os.path.join(dirpath, f), root_dir)
            swift_files.append((f, rel))
        elif f in ['phone_engine.html', 'jssip.min.js']:
            rel = os.path.relpath(os.path.join(dirpath, f), root_dir)
            resource_files.append((f, rel))

# Assets.xcassets is treated as a single resource directory
assets_rel = "AiPBX/Resources/Assets.xcassets"
resource_files.append(("Assets.xcassets", assets_rel))

out = []
out.append("// !$*UTF8*$!")
out.append("{")
out.append("\tarchiveVersion = 1;")
out.append("\tclasses = {")
out.append("\t};")
out.append("\tobjectVersion = 56;")
out.append("\tobjects = {")

# 1. PBXBuildFile
out.append("\n/* Begin PBXBuildFile section */")
for f, rel in swift_files:
    bid = gen_id("buildfile_" + rel)
    fid = gen_id("fileref_" + rel)
    out.append(f"\t\t{bid} /* {f} in Sources */ = {{isa = PBXBuildFile; fileRef = {fid} /* {f} */; }};")

for f, rel in resource_files:
    bid = gen_id("buildfile_" + rel)
    fid = gen_id("fileref_" + rel)
    out.append(f"\t\t{bid} /* {f} in Resources */ = {{isa = PBXBuildFile; fileRef = {fid} /* {f} */; }};")

# Framework build files
frameworks = ["WebKit.framework", "AVFoundation.framework", "CallKit.framework", "UserNotifications.framework"]
for fw in frameworks:
    bid = gen_id("buildfile_fw_" + fw)
    fid = gen_id("fileref_fw_" + fw)
    out.append(f"\t\t{bid} /* {fw} in Frameworks */ = {{isa = PBXBuildFile; fileRef = {fid} /* {fw} */; }};")

out.append("/* End PBXBuildFile section */")

# 2. PBXFileReference
out.append("\n/* Begin PBXFileReference section */")
product_ref_id = gen_id("product_app")
out.append(f"\t\t{product_ref_id} /* AiPBX.app */ = {{isa = PBXFileReference; explicitFileType = wrapper.application; includeInIndex = 0; path = AiPBX.app; sourceTree = BUILT_PRODUCTS_DIR; }};")

for f, rel in swift_files:
    fid = gen_id("fileref_" + rel)
    out.append(f"\t\t{fid} /* {f} */ = {{isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = \"{f}\"; sourceTree = \"<group>\"; }};")

for f, rel in resource_files:
    fid = gen_id("fileref_" + rel)
    if f.endswith('.html'):
        ft = "text.html"
    elif f.endswith('.js'):
        ft = "sourcecode.javascript"
    elif f.endswith('.xcassets'):
        ft = "folder.assetcatalog"
    else:
        ft = "text"
    out.append(f"\t\t{fid} /* {f} */ = {{isa = PBXFileReference; lastKnownFileType = {ft}; path = \"{f}\"; sourceTree = \"<group>\"; }};")

# Info.plist
info_plist_fid = gen_id("fileref_Info.plist")
out.append(f"\t\t{info_plist_fid} /* Info.plist */ = {{isa = PBXFileReference; lastKnownFileType = text.plist.xml; path = \"Info.plist\"; sourceTree = \"<group>\"; }};")

# Framework file references
for fw in frameworks:
    fid = gen_id("fileref_fw_" + fw)
    out.append(f"\t\t{fid} /* {fw} */ = {{isa = PBXFileReference; lastKnownFileType = wrapper.framework; name = {fw}; path = System/Library/Frameworks/{fw}; sourceTree = SDKROOT; }};")

out.append("/* End PBXFileReference section */")

# 3. PBXFrameworksBuildPhase
fw_phase_id = gen_id("phase_frameworks")
out.append("\n/* Begin PBXFrameworksBuildPhase section */")
out.append(f"\t\t{fw_phase_id} /* Frameworks */ = {{")
out.append("\t\t\tisa = PBXFrameworksBuildPhase;")
out.append("\t\t\tbuildActionMask = 2147483647;")
out.append("\t\t\tfiles = (")
for fw in frameworks:
    bid = gen_id("buildfile_fw_" + fw)
    out.append(f"\t\t\t\t{bid} /* {fw} in Frameworks */,")
out.append("\t\t\t);")
out.append("\t\t\trunOnlyForDeploymentPostprocessing = 0;")
out.append("\t\t};")
out.append("/* End PBXFrameworksBuildPhase section */")

# 4. Groups
out.append("\n/* Begin PBXGroup section */")

# Build tree of groups
groups = {}
def get_or_create_group(name, path="", parent=None):
    gid = gen_id("group_" + (parent or "") + "/" + name)
    if gid not in groups:
        groups[gid] = {"name": name, "path": path, "children": []}
    return gid

main_group_id = gen_id("main_group")
products_group_id = gen_id("products_group")
frameworks_group_id = gen_id("frameworks_group")
aipbx_group_id = gen_id("aipbx_group")

# Frameworks group
groups[frameworks_group_id] = {
    "name": "Frameworks",
    "path": "",
    "children": [(gen_id("fileref_fw_" + fw), fw) for fw in frameworks]
}

# Products group
groups[products_group_id] = {
    "name": "Products",
    "path": "",
    "children": [(product_ref_id, "AiPBX.app")]
}

# Group subdirectories inside AiPBX
subgroups = ["App", "Models", "Services", "Views", "Resources"]
views_subgroups = ["Dialer", "History", "Contacts", "Chat", "Settings", "Components"]

subgroup_ids = {}
for sg in subgroups:
    gid = gen_id("group_AiPBX/" + sg)
    subgroup_ids[sg] = gid
    groups[gid] = {"name": sg, "path": sg, "children": []}

views_subgroup_ids = {}
for vsg in views_subgroups:
    gid = gen_id("group_AiPBX/Views/" + vsg)
    views_subgroup_ids[vsg] = gid
    groups[gid] = {"name": vsg, "path": vsg, "children": []}

for vsg in views_subgroups:
    groups[subgroup_ids["Views"]]["children"].append((views_subgroup_ids[vsg], vsg))

# Populate children
for f, rel in swift_files:
    fid = gen_id("fileref_" + rel)
    parts = rel.split(os.sep)
    # parts: ['AiPBX', 'Views', 'Dialer', 'DialerTabView.swift']
    if len(parts) == 4 and parts[1] == "Views":
        vsg = parts[2]
        if vsg in views_subgroup_ids:
            groups[views_subgroup_ids[vsg]]["children"].append((fid, f))
    elif len(parts) == 3:
        sg = parts[1]
        if sg in subgroup_ids:
            groups[subgroup_ids[sg]]["children"].append((fid, f))
    elif len(parts) == 2:
        # Direct inside AiPBX/
        groups[subgroup_ids["App"]]["children"].append((fid, f))

for f, rel in resource_files:
    fid = gen_id("fileref_" + rel)
    groups[subgroup_ids["Resources"]]["children"].append((fid, f))

# Add Info.plist to Resources group
groups[subgroup_ids["Resources"]]["children"].append((info_plist_fid, "Info.plist"))

# AiPBX Group
groups[aipbx_group_id] = {
    "name": "AiPBX",
    "path": "AiPBX",
    "children": [(subgroup_ids[sg], sg) for sg in subgroups]
}

# Main Group
groups[main_group_id] = {
    "name": "",
    "path": "",
    "children": [
        (aipbx_group_id, "AiPBX"),
        (frameworks_group_id, "Frameworks"),
        (products_group_id, "Products")
    ]
}

for gid, g in sorted(groups.items()):
    out.append(f"\t\t{gid} /* {g['name']} */ = {{")
    out.append("\t\t\tisa = PBXGroup;")
    out.append("\t\t\tchildren = (")
    for cid, cname in g["children"]:
        out.append(f"\t\t\t\t{cid} /* {cname} */,")
    out.append("\t\t\t);")
    if g["name"]:
        out.append(f"\t\t\tname = \"{g['name']}\";")
    if g["path"]:
        out.append(f"\t\t\tpath = \"{g['path']}\";")
    out.append("\t\t\tsourceTree = \"<group>\";")
    out.append("\t\t};")

out.append("/* End PBXGroup section */")

# 5. Native Target
target_id = gen_id("target_AiPBX")
sources_phase_id = gen_id("phase_sources")
resources_phase_id = gen_id("phase_resources")
target_config_list_id = gen_id("configlist_target")

out.append("\n/* Begin PBXNativeTarget section */")
out.append(f"\t\t{target_id} /* AiPBX */ = {{")
out.append("\t\t\tisa = PBXNativeTarget;")
out.append(f"\t\t\tbuildConfigurationList = {target_config_list_id} /* Build configuration list for PBXNativeTarget \"AiPBX\" */;")
out.append("\t\t\tbuildPhases = (")
out.append(f"\t\t\t\t{sources_phase_id} /* Sources */,")
out.append(f"\t\t\t\t{fw_phase_id} /* Frameworks */,")
out.append(f"\t\t\t\t{resources_phase_id} /* Resources */,")
out.append("\t\t\t);")
out.append("\t\t\tbuildRules = (")
out.append("\t\t\t);")
out.append("\t\t\tdependencies = (")
out.append("\t\t\t);")
out.append("\t\t\tname = AiPBX;")
out.append(f"\t\t\tproductName = AiPBX;")
out.append(f"\t\t\tproductReference = {product_ref_id} /* AiPBX.app */;")
out.append("\t\t\tproductType = \"com.apple.product-type.application\";")
out.append("\t\t};")
out.append("/* End PBXNativeTarget section */")

# 6. PBXProject
project_id = gen_id("project_AiPBX")
project_config_list_id = gen_id("configlist_project")

out.append("\n/* Begin PBXProject section */")
out.append(f"\t\t{project_id} /* Project object */ = {{")
out.append("\t\t\tisa = PBXProject;")
out.append("\t\t\tattributes = {")
out.append("\t\t\t\tBuildIndependentTargetsInParallel = YES;")
out.append("\t\t\t\tLastUpgradeCheck = 1500;")
out.append("\t\t\t\tTargetAttributes = {")
out.append(f"\t\t\t\t\t{target_id} = {{")
out.append("\t\t\t\t\t\tCreatedOnToolsVersion = 15.0;")
out.append("\t\t\t\t\t};")
out.append("\t\t\t\t};")
out.append("\t\t\t};")
out.append(f"\t\t\tbuildConfigurationList = {project_config_list_id} /* Build configuration list for PBXProject \"AiPBX\" */;")
out.append("\t\t\tcompatibilityVersion = \"Xcode 14.0\";")
out.append("\t\t\tdevelopmentRegion = en;")
out.append("\t\t\thasScannedForEncodings = 0;")
out.append("\t\t\tknownRegions = (")
out.append("\t\t\t\ten,")
out.append("\t\t\t\tBase,")
out.append("\t\t\t);")
out.append(f"\t\t\tmainGroup = {main_group_id};")
out.append(f"\t\t\tproductRefGroup = {products_group_id} /* Products */;")
out.append("\t\t\tprojectDirPath = \"\";")
out.append("\t\t\tprojectRoot = \"\";")
out.append("\t\t\ttargets = (")
out.append(f"\t\t\t\t{target_id} /* AiPBX */,")
out.append("\t\t\t);")
out.append("\t\t};")
out.append("/* End PBXProject section */")

# 7. PBXResourcesBuildPhase
out.append("\n/* Begin PBXResourcesBuildPhase section */")
out.append(f"\t\t{resources_phase_id} /* Resources */ = {{")
out.append("\t\t\tisa = PBXResourcesBuildPhase;")
out.append("\t\t\tbuildActionMask = 2147483647;")
out.append("\t\t\tfiles = (")
for f, rel in resource_files:
    bid = gen_id("buildfile_" + rel)
    out.append(f"\t\t\t\t{bid} /* {f} in Resources */,")
out.append("\t\t\t);")
out.append("\t\t\trunOnlyForDeploymentPostprocessing = 0;")
out.append("\t\t};")
out.append("/* End PBXResourcesBuildPhase section */")

# 8. PBXSourcesBuildPhase
out.append("\n/* Begin PBXSourcesBuildPhase section */")
out.append(f"\t\t{sources_phase_id} /* Sources */ = {{")
out.append("\t\t\tisa = PBXSourcesBuildPhase;")
out.append("\t\t\tbuildActionMask = 2147483647;")
out.append("\t\t\tfiles = (")
for f, rel in swift_files:
    bid = gen_id("buildfile_" + rel)
    out.append(f"\t\t\t\t{bid} /* {f} in Sources */,")
out.append("\t\t\t);")
out.append("\t\t\trunOnlyForDeploymentPostprocessing = 0;")
out.append("\t\t};")
out.append("/* End PBXSourcesBuildPhase section */")

# 9. Build Configurations
debug_proj_cfg_id = gen_id("cfg_proj_debug")
release_proj_cfg_id = gen_id("cfg_proj_release")
debug_target_cfg_id = gen_id("cfg_target_debug")
release_target_cfg_id = gen_id("cfg_target_release")

out.append("\n/* Begin XCBuildConfiguration section */")
# Project Debug
out.append(f"\t\t{debug_proj_cfg_id} /* Debug */ = {{")
out.append("\t\t\tisa = XCBuildConfiguration;")
out.append("\t\t\tbuildSettings = {")
out.append("\t\t\t\tALWAYS_SEARCH_USER_PATHS = NO;")
out.append("\t\t\t\tCLANG_ANALYZER_NONNULL = YES;")
out.append("\t\t\t\tCLANG_ENABLE_MODULES = YES;")
out.append("\t\t\t\tCLANG_ENABLE_OBJC_ARC = YES;")
out.append("\t\t\t\tCOPY_PHASE_STRIP = NO;")
out.append("\t\t\t\tDEBUG_INFORMATION_FORMAT = dwarf;")
out.append("\t\t\t\tENABLE_TESTABILITY = YES;")
out.append("\t\t\t\tGCC_DYNAMIC_NO_PIC = NO;")
out.append("\t\t\t\tGCC_OPTIMIZATION_LEVEL = 0;")
out.append("\t\t\t\tGCC_PREPROCESSOR_DEFINITIONS = (")
out.append("\t\t\t\t\t\"DEBUG=1\",")
out.append("\t\t\t\t\t\"$(inherited)\",")
out.append("\t\t\t\t);")
out.append("\t\t\t\tIPHONEOS_DEPLOYMENT_TARGET = 16.0;")
out.append("\t\t\t\tMTL_ENABLE_DEBUG_INFO = INCLUDE_SOURCE;")
out.append("\t\t\t\tONLY_ACTIVE_ARCH = YES;")
out.append("\t\t\t\tSDKROOT = iphoneos;")
out.append("\t\t\t\tSWIFT_ACTIVE_COMPILATION_CONDITIONS = DEBUG;")
out.append("\t\t\t\tSWIFT_OPTIMIZATION_LEVEL = \"-Onone\";")
out.append("\t\t\t};")
out.append("\t\t\tname = Debug;")
out.append("\t\t};")

# Project Release
out.append(f"\t\t{release_proj_cfg_id} /* Release */ = {{")
out.append("\t\t\tisa = XCBuildConfiguration;")
out.append("\t\t\tbuildSettings = {")
out.append("\t\t\t\tALWAYS_SEARCH_USER_PATHS = NO;")
out.append("\t\t\t\tCLANG_ANALYZER_NONNULL = YES;")
out.append("\t\t\t\tCLANG_ENABLE_MODULES = YES;")
out.append("\t\t\t\tCLANG_ENABLE_OBJC_ARC = YES;")
out.append("\t\t\t\tCOPY_PHASE_STRIP = NO;")
out.append("\t\t\t\tDEBUG_INFORMATION_FORMAT = \"dwarf-with-dsym\";")
out.append("\t\t\t\tENABLE_NS_ASSERTIONS = NO;")
out.append("\t\t\t\tIPHONEOS_DEPLOYMENT_TARGET = 16.0;")
out.append("\t\t\t\tMTL_ENABLE_DEBUG_INFO = NO;")
out.append("\t\t\t\tSDKROOT = iphoneos;")
out.append("\t\t\t\tSWIFT_COMPILATION_MODE = wholemodule;")
out.append("\t\t\t\tSWIFT_OPTIMIZATION_LEVEL = \"-O\";")
out.append("\t\t\t\tVALIDATE_PRODUCT = YES;")
out.append("\t\t\t};")
out.append("\t\t\tname = Release;")
out.append("\t\t};")

# Target Debug
out.append(f"\t\t{debug_target_cfg_id} /* Debug */ = {{")
out.append("\t\t\tisa = XCBuildConfiguration;")
out.append("\t\t\tbuildSettings = {")
out.append("\t\t\t\tASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;")
out.append("\t\t\t\tASSETCATALOG_COMPILER_GLOBAL_ACCENT_COLOR_NAME = AccentColor;")
out.append("\t\t\t\tCODE_SIGN_STYLE = Automatic;")
out.append("\t\t\t\tCURRENT_PROJECT_VERSION = 1;")
out.append("\t\t\t\tENABLE_PREVIEWS = YES;")
out.append("\t\t\t\tGENERATE_INFOPLIST_FILE = NO;")
out.append("\t\t\t\tINFOPLIST_FILE = AiPBX/Resources/Info.plist;")
out.append("\t\t\t\tLD_RUNPATH_SEARCH_PATHS = (")
out.append("\t\t\t\t\t\"$(inherited)\",")
out.append("\t\t\t\t\t\"@executable_path/Frameworks\",")
out.append("\t\t\t\t);")
out.append("\t\t\t\tMARKETING_VERSION = 1.0.0;")
out.append("\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = com.mhrgl.AiPBX;")
out.append("\t\t\t\tPRODUCT_NAME = \"$(TARGET_NAME)\";")
out.append("\t\t\t\tSWIFT_EMIT_LOC_STRINGS = YES;")
out.append("\t\t\t\tSWIFT_VERSION = 5.0;")
out.append("\t\t\t\tTARGETED_DEVICE_FAMILY = \"1\";")
out.append("\t\t\t};")
out.append("\t\t\tname = Debug;")
out.append("\t\t};")

# Target Release
out.append(f"\t\t{release_target_cfg_id} /* Release */ = {{")
out.append("\t\t\tisa = XCBuildConfiguration;")
out.append("\t\t\tbuildSettings = {")
out.append("\t\t\t\tASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;")
out.append("\t\t\t\tASSETCATALOG_COMPILER_GLOBAL_ACCENT_COLOR_NAME = AccentColor;")
out.append("\t\t\t\tCODE_SIGN_STYLE = Automatic;")
out.append("\t\t\t\tCURRENT_PROJECT_VERSION = 1;")
out.append("\t\t\t\tENABLE_PREVIEWS = YES;")
out.append("\t\t\t\tGENERATE_INFOPLIST_FILE = NO;")
out.append("\t\t\t\tINFOPLIST_FILE = AiPBX/Resources/Info.plist;")
out.append("\t\t\t\tLD_RUNPATH_SEARCH_PATHS = (")
out.append("\t\t\t\t\t\"$(inherited)\",")
out.append("\t\t\t\t\t\"@executable_path/Frameworks\",")
out.append("\t\t\t\t);")
out.append("\t\t\t\tMARKETING_VERSION = 1.0.0;")
out.append("\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = com.mhrgl.AiPBX;")
out.append("\t\t\t\tPRODUCT_NAME = \"$(TARGET_NAME)\";")
out.append("\t\t\t\tSWIFT_EMIT_LOC_STRINGS = YES;")
out.append("\t\t\t\tSWIFT_VERSION = 5.0;")
out.append("\t\t\t\tTARGETED_DEVICE_FAMILY = \"1\";")
out.append("\t\t\t};")
out.append("\t\t\tname = Release;")
out.append("\t\t};")

out.append("/* End XCBuildConfiguration section */")

# 10. XCConfigurationList
out.append("\n/* Begin XCConfigurationList section */")
out.append(f"\t\t{project_config_list_id} /* Build configuration list for PBXProject \"AiPBX\" */ = {{")
out.append("\t\t\tisa = XCConfigurationList;")
out.append("\t\t\tbuildConfigurations = (")
out.append(f"\t\t\t\t{debug_proj_cfg_id} /* Debug */,")
out.append(f"\t\t\t\t{release_proj_cfg_id} /* Release */,")
out.append("\t\t\t);")
out.append("\t\t\tdefaultConfigurationIsVisible = 0;")
out.append("\t\t\tdefaultConfigurationName = Release;")
out.append("\t\t};")

out.append(f"\t\t{target_config_list_id} /* Build configuration list for PBXNativeTarget \"AiPBX\" */ = {{")
out.append("\t\t\tisa = XCConfigurationList;")
out.append("\t\t\tbuildConfigurations = (")
out.append(f"\t\t\t\t{debug_target_cfg_id} /* Debug */,")
out.append(f"\t\t\t\t{release_target_cfg_id} /* Release */,")
out.append("\t\t\t);")
out.append("\t\t\tdefaultConfigurationIsVisible = 0;")
out.append("\t\t\tdefaultConfigurationName = Release;")
out.append("\t\t};")
out.append("/* End XCConfigurationList section */")

out.append("\t};")
out.append(f"\trootObject = {project_id} /* Project object */;")
out.append("}")

pbxproj_path = os.path.join(root_dir, "AiPBX.xcodeproj", "project.pbxproj")
os.makedirs(os.path.dirname(pbxproj_path), exist_ok=True)
with open(pbxproj_path, "w", encoding="utf-8") as f:
    f.write("\n".join(out) + "\n")

print(f"Generated {pbxproj_path} with {len(swift_files)} Swift files and {len(resource_files)} resources.")
